<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineInstallScript;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class ProvisionBootstrap
{
    // Bound local parsing/compression independently of each provider's wire limit.
    private const MAX_CONFIG_BYTES = 65535;

    public static function compactFiles(string $bootstrap): string
    {
        if (strlen($bootstrap) > self::MAX_CONFIG_BYTES) throw new CloudException('bootstrap_too_large');
        if (!str_starts_with($bootstrap, '#cloud-config')) throw new CloudException('invalid_bootstrap_identity');
        try { $data = Yaml::parse($bootstrap); }
        catch (\Throwable) { throw new CloudException('invalid_bootstrap_identity'); }
        if (!is_array($data)) throw new CloudException('invalid_bootstrap_identity');
        if (!isset($data['write_files'])) return $bootstrap;
        if (!is_array($data['write_files']) || !array_is_list($data['write_files'])) throw new CloudException('invalid_bootstrap_identity');
        $changed = false;
        foreach ($data['write_files'] as &$file) {
            if (!is_array($file)) throw new CloudException('invalid_bootstrap_identity');
            if (!is_string($file['content'] ?? null) || strlen($file['content']) < 1024
                || ($file['encoding'] ?? 'text/plain') !== 'text/plain') continue;
            if (!function_exists('gzencode')) throw new CloudException('bootstrap_compression_unavailable');
            $compressed = gzencode($file['content'], 9);
            if ($compressed === false) throw new CloudException('bootstrap_compression_unavailable');
            $encoded = base64_encode($compressed);
            if (strlen($encoded) + 32 >= strlen($file['content'])) continue;
            $file['encoding'] = 'gz+b64'; $file['content'] = $encoded; $changed = true;
        }
        unset($file);
        return $changed ? "#cloud-config\n" . Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : $bootstrap;
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($parts['host'], '.')
            || filter_var($parts['host'], FILTER_VALIDATE_IP) || isset($parts['user']) || isset($parts['pass'])
            || str_ends_with(strtolower($parts['host']), '.localhost')
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && $parts['port'] !== 443)) throw new CloudException('invalid_panel_origin');
        return 'https://' . strtolower($parts['host']);
    }

    public function configuredOrigin(): string
    {
        $url = trim((string) admin_setting('node_api_base_url', ''));
        return $this->origin($url !== '' ? $url : trim((string) admin_setting('app_url', '')));
    }

    public function build(ServerMachine $machine, string $origin, string $task, string $installation, string $secret): string
    {
        $origin = $this->origin($origin);
        if (!Str::isUuid($task) || !preg_match('/^[a-f0-9]{32}$/D', $installation)
            || !preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new CloudException('invalid_bootstrap_identity');
        $directory = '/var/lib/kelinode-provision/' . $task;
        $install = str_replace(["\r\n", "\r"], "\n", app(MachineInstallScript::class)->render($origin, $machine, 'kelinode-rs'));
        $diagnostics = strtr((string) file_get_contents(resource_path('server-machine/provision-diagnostics.sh')),
            ['{{DIRECTORY}}' => $directory, '{{IDENTITY}}' => $installation, '{{TASK}}' => $task]);
        $script = <<<'SH'
#!/usr/bin/env bash
{{DIAGNOSTICS}}
# A child Bash sources only the diagnostic prefix, never the provisioning body.
if [ "${BASH_SOURCE[0]}" != "$0" ]; then return 0; fi
set -Eeuo pipefail
umask 077
directory='{{DIRECTORY}}'
identity='{{IDENTITY}}'
id_file=/etc/kelinode/report-instance-id
report_result() {
    code=$?
    trap - EXIT
    if declare -F keli_provision_exit >/dev/null; then keli_provision_exit "$code" "" || true; fi
    state=failed
    if [ "$code" -eq 0 ]; then state=succeeded; : > "$directory/succeeded"; fi
    printf '{"task_id":"{{TASK}}","installation_id":"%s","state":"%s"}' "$identity" "$state" > "$directory/result.json"
    if command -v curl >/dev/null 2>&1; then
        curl --fail --silent --show-error --connect-timeout 3 --max-time 10 --retry 3 --retry-delay 2 --retry-max-time 25 --config "$directory/callback.conf" --data-binary "@$directory/result.json" --output /dev/null 2>/dev/null || true
    fi
    exit "$code"
}
trap report_result EXIT
if declare -F keli_provision_stage >/dev/null; then keli_provision_stage identity || true; fi
if [ -L /etc/kelinode ] || [ -L "$id_file" ]; then keli_provision_exit 82 "$LINENO"; exit 82; fi
mkdir -p /etc/kelinode
if [ -e "$id_file" ]; then
    [ "$(cat "$id_file")" = "$identity" ] || { keli_provision_exit 83 "$LINENO"; exit 83; }
    [ -f "$id_file" ] || { keli_provision_exit 84 "$LINENO"; exit 84; }
    chmod 600 "$id_file"
else
    temporary="${id_file}.{{TASK}}"
    printf '%s' "$identity" > "$temporary"
    chmod 600 "$temporary"
    ln "$temporary" "$id_file"
    rm -f "$temporary"
fi
if [ -f "$directory/succeeded" ]; then
    if declare -F keli_provision_stage >/dev/null; then keli_provision_stage already_completed || true; fi
    exit 0
fi
if declare -F keli_provision_stage >/dev/null; then
    keli_provision_stage installer || true
    export BASH_ENV="$directory/bootstrap.sh"
fi
timeout --signal=TERM --kill-after=15s 600s bash "$directory/install.sh" > "$directory/install.log" 2>&1
if declare -F keli_provision_stage >/dev/null; then keli_provision_stage complete || true; fi
SH;
        $script = str_replace(["\r\n", "\r"], "\n", strtr($script, ['{{DIRECTORY}}' => $directory, '{{IDENTITY}}' => $installation,
            '{{TASK}}' => $task, '{{DIAGNOSTICS}}' => $diagnostics]));
        $callback = 'url = "' . $origin . '/api/v2/server/machine/provision/result"' . "\n"
            . 'header = "Authorization: Bearer ' . $secret . '"' . "\nheader = \"Content-Type: application/json\"\n";
        $files = [];
        foreach (['install.sh' => $install, 'bootstrap.sh' => $script, 'callback.conf' => $callback] as $name => $body) {
            $files[] = ['path' => $directory . '/' . $name, 'owner' => 'root:root', 'permissions' => '0600', 'content' => $body];
        }
        $data = self::compactFiles("#cloud-config\n" . Yaml::dump(['write_files' => $files, 'runcmd' => [['bash', $directory . '/bootstrap.sh']]], 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        if (strlen(base64_encode($data)) > 65535) throw new CloudException('bootstrap_too_large');
        return $data;
    }
}
