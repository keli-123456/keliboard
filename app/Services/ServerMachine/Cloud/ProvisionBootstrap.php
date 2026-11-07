<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineInstallScript;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class ProvisionBootstrap
{
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
        $script = <<<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
directory='{{DIRECTORY}}'
identity='{{IDENTITY}}'
id_file=/etc/kelinode/report-instance-id
report_result() {
    code=$?
    trap - EXIT
    state=failed
    if [ "$code" -eq 0 ]; then state=succeeded; : > "$directory/succeeded"; fi
    printf '{"task_id":"{{TASK}}","installation_id":"%s","state":"%s"}' "$identity" "$state" > "$directory/result.json"
    if command -v curl >/dev/null 2>&1; then
        curl --fail --silent --show-error --connect-timeout 3 --max-time 10 --retry 3 --retry-delay 2 --retry-max-time 25 --config "$directory/callback.conf" --data-binary "@$directory/result.json" --output /dev/null 2>/dev/null || true
    fi
    exit "$code"
}
trap report_result EXIT
if [ -L /etc/kelinode ] || [ -L "$id_file" ]; then exit 82; fi
mkdir -p /etc/kelinode
if [ -e "$id_file" ]; then
    [ "$(cat "$id_file")" = "$identity" ] || exit 83
    [ -f "$id_file" ] || exit 84
    chmod 600 "$id_file"
else
    temporary="${id_file}.{{TASK}}"
    printf '%s' "$identity" > "$temporary"
    chmod 600 "$temporary"
    ln "$temporary" "$id_file"
    rm -f "$temporary"
fi
if [ -f "$directory/succeeded" ]; then exit 0; fi
timeout --signal=TERM --kill-after=15s 600s bash "$directory/install.sh" > "$directory/install.log" 2>&1
SH;
        $script = str_replace(["\r\n", "\r"], "\n", strtr($script, ['{{DIRECTORY}}' => $directory, '{{IDENTITY}}' => $installation, '{{TASK}}' => $task]));
        $callback = 'url = "' . $origin . '/api/v2/server/machine/provision/result"' . "\n"
            . 'header = "Authorization: Bearer ' . $secret . '"' . "\nheader = \"Content-Type: application/json\"\n";
        $files = [];
        foreach (['install.sh' => $install, 'bootstrap.sh' => $script, 'callback.conf' => $callback] as $name => $body) {
            $files[] = ['path' => $directory . '/' . $name, 'owner' => 'root:root', 'permissions' => '0600', 'content' => $body];
        }
        $data = "#cloud-config\n" . Yaml::dump(['write_files' => $files, 'runcmd' => [['bash', $directory . '/bootstrap.sh']]], 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (strlen(base64_encode($data)) > 65535) throw new CloudException('bootstrap_too_large');
        return $data;
    }
}
