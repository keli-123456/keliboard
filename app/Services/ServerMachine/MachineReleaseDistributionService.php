<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MachineReleaseDistributionService
{
    public const SOURCE_GITHUB = 'github';
    public const SOURCE_PANEL = 'panel';
    public const SOURCE_CUSTOM = 'custom';
    public const PLATFORM_LINUX_X86_64 = 'linux-x86_64';

    public function source(): string
    {
        return $this->normalizeSource(admin_setting('server_machine_distribution_source', self::SOURCE_GITHUB));
    }

    public function normalizeSource(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, [self::SOURCE_GITHUB, self::SOURCE_PANEL, self::SOURCE_CUSTOM], true)
            ? $value
            : self::SOURCE_GITHUB;
    }

    public function customBaseUrl(): string
    {
        return rtrim(trim((string) admin_setting('server_machine_distribution_base_url', '')), '/');
    }

    public function releaseBaseUrl(string $panelBaseUrl): string
    {
        $source = $this->source();
        if ($source === self::SOURCE_CUSTOM && $this->customBaseUrl() !== '') {
            return $this->customBaseUrl() . '/releases';
        }
        if ($source === self::SOURCE_PANEL) {
            return rtrim($panelBaseUrl, '/') . '/server/machine/releases';
        }
        return '';
    }

    public function installScriptUrl(string $panelBaseUrl, string $component = 'kelinode-rs'): string
    {
        $source = $this->source();
        if ($source === self::SOURCE_CUSTOM && $this->customBaseUrl() !== '') {
            return $this->customBaseUrl() . '/' . trim($component, '/') . '/install.sh';
        }
        if ($source === self::SOURCE_PANEL) {
            return rtrim($panelBaseUrl, '/') . '/server/machine/' . trim($component, '/') . '/install.sh';
        }
        return 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh';
    }

    public function validateMachine(Request $request): ?ServerMachine
    {
        $machineId = (int) $request->query('machine_id', $request->input('machine_id'));
        $token = trim((string) $request->query('machine_token', $request->input('machine_token', '')));
        if ($machineId <= 0 || $token === '') {
            return null;
        }

        $machine = ServerMachine::find($machineId);
        if (!$machine || !hash_equals((string) $machine->token, $token)) {
            return null;
        }

        return $machine;
    }

    public function componentFromUpgradeComponent(string $component): string
    {
        return $component === 'core' ? 'keli-core-rs' : 'kelinode-rs';
    }

    public function normalizeComponent(mixed $component): ?string
    {
        $component = strtolower(trim((string) $component));
        return in_array($component, ['kelinode-rs', 'keli-core-rs'], true) ? $component : null;
    }

    public function normalizePlatform(mixed $platform): ?string
    {
        $platform = trim((string) $platform);
        return $platform === self::PLATFORM_LINUX_X86_64 ? $platform : null;
    }

    public function normalizeVersion(mixed $version): ?string
    {
        $version = trim((string) $version);
        if (!preg_match('/^v[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/', $version)) {
            return null;
        }
        return $version;
    }

    public function manifestPath(string $component, string $version, string $platform): string
    {
        $asset = $this->assetPrefix($component, $version, $platform) . '.manifest.json';
        return $this->artifactDirectory($component, $version, $platform) . '/' . $asset;
    }

    public function archivePath(string $component, string $version, string $platform): string
    {
        $asset = $this->assetPrefix($component, $version, $platform) . '.tar.gz';
        return $this->artifactDirectory($component, $version, $platform) . '/' . $asset;
    }

    public function latestLocalVersion(string $component, string $platform): ?string
    {
        $component = $this->normalizeComponent($component) ?? '';
        $platform = $this->normalizePlatform($platform) ?? '';
        if ($component === '' || $platform === '') {
            return null;
        }

        $root = 'kelinode-rs/releases/' . $component;
        $directories = Storage::disk('local')->directories($root);
        $versions = [];
        foreach ($directories as $dir) {
            $version = basename(str_replace('\\', '/', $dir));
            if ($this->normalizeVersion($version) === null) {
                continue;
            }
            if (!Storage::disk('local')->exists($this->manifestPath($component, $version, $platform))) {
                continue;
            }
            $versions[] = $version;
        }

        usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($b, 'vV'), ltrim($a, 'vV')));
        return $versions[0] ?? null;
    }

    public function releaseAuth(ServerMachine $machine): array
    {
        return [
            'machine_id' => (string) $machine->id,
            'machine_token' => (string) $machine->token,
        ];
    }

    private function artifactDirectory(string $component, string $version, string $platform): string
    {
        return implode('/', ['kelinode-rs/releases', $component, $version, $platform]);
    }

    private function assetPrefix(string $component, string $version, string $platform): string
    {
        $assetName = $component === 'keli-core-rs' ? 'keli-core-rs' : 'keli-native-node';
        return Str::of($assetName . '-' . $version . '-' . $platform)->toString();
    }
}
