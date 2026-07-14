<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\ServerMachineRelease;
use App\Models\ServerMachine;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            return $this->panelApiBaseUrl($panelBaseUrl) . '/server/machine/releases';
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
            return $this->panelApiBaseUrl($panelBaseUrl) . '/server/machine/' . trim($component, '/') . '/install.sh';
        }
        return 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh';
    }

    private function panelApiBaseUrl(string $panelBaseUrl): string
    {
        $baseUrl = rtrim($panelBaseUrl, '/');
        return preg_match('#/api/v2$#', $baseUrl) ? $baseUrl : $baseUrl . '/api/v2';
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

        $defaultVersion = $this->latestDefaultLocalVersion($component, $platform);
        if ($defaultVersion !== null) {
            return $defaultVersion;
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

    public function listLocalReleases(): array
    {
        if (!$this->releaseTableExists()) {
            return [];
        }

        return ServerMachineRelease::query()
            ->where('status', ServerMachineRelease::STATUS_ACTIVE)
            ->orderBy('component')
            ->orderBy('platform')
            ->orderByDesc('is_default')
            ->get()
            ->sort(function (ServerMachineRelease $a, ServerMachineRelease $b): int {
                $targetCompare = [$a->component, $a->platform] <=> [$b->component, $b->platform];
                if ($targetCompare !== 0) {
                    return $targetCompare;
                }
                if ((bool) $a->is_default !== (bool) $b->is_default) {
                    return $a->is_default ? -1 : 1;
                }
                return version_compare(ltrim((string) $b->version, 'vV'), ltrim((string) $a->version, 'vV'));
            })
            ->values()
            ->map(fn (ServerMachineRelease $release): array => $release->toAdminPayload())
            ->all();
    }

    public function findLocalRelease(string $component, string $version, string $platform): ?ServerMachineRelease
    {
        $component = $this->normalizeComponent($component) ?? '';
        $version = $this->normalizeVersion($version) ?? '';
        $platform = $this->normalizePlatform($platform) ?? '';
        if ($component === '' || $version === '' || $platform === '' || !$this->releaseTableExists()) {
            return null;
        }

        $release = ServerMachineRelease::query()
            ->where('component', $component)
            ->where('version', $version)
            ->where('platform', $platform)
            ->where('status', ServerMachineRelease::STATUS_ACTIVE)
            ->first();

        if (
            !$release
            || !Storage::disk('local')->exists((string) $release->manifest_path)
            || !Storage::disk('local')->exists((string) $release->archive_path)
        ) {
            return null;
        }

        return $release;
    }

    public function storeLocalRelease(
        string $component,
        string $version,
        string $platform,
        UploadedFile $manifest,
        UploadedFile $archive,
        bool $makeDefault = false
    ): ServerMachineRelease {
        $component = $this->normalizeComponent($component) ?? '';
        $version = $this->normalizeVersion($version) ?? '';
        $platform = $this->normalizePlatform($platform) ?? '';
        if ($component === '' || $version === '' || $platform === '') {
            throw new InvalidArgumentException('无效的版本目标');
        }

        $manifestContent = (string) file_get_contents((string) $manifest->getRealPath());
        $manifestData = json_decode($manifestContent, true);
        if (!is_array($manifestData)) {
            throw new InvalidArgumentException('Manifest 不是有效 JSON');
        }

        $archivePath = (string) $archive->getRealPath();
        $sha256 = strtolower((string) hash_file('sha256', $archivePath));
        $manifestSha256 = strtolower(trim((string) data_get($manifestData, 'sha256', '')));
        if ($manifestSha256 === '' || !hash_equals($sha256, $manifestSha256)) {
            throw new InvalidArgumentException('Manifest SHA256 与压缩包不一致');
        }

        $binarySha256 = strtolower(trim((string) data_get($manifestData, 'binary_sha256', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $binarySha256)) {
            throw new InvalidArgumentException('Manifest binary_sha256 无效');
        }
        if (!$this->manifestComponentMatches($manifestData, $component)) {
            throw new InvalidArgumentException('Manifest component 与表单不一致');
        }
        if (trim((string) data_get($manifestData, 'version', '')) !== $version) {
            throw new InvalidArgumentException('Manifest version 与表单不一致');
        }
        if (trim((string) data_get($manifestData, 'platform', '')) !== $platform) {
            throw new InvalidArgumentException('Manifest platform 与表单不一致');
        }

        $manifestPath = $this->manifestPath($component, $version, $platform);
        $releaseArchivePath = $this->archivePath($component, $version, $platform);
        Storage::disk('local')->put($manifestPath, $manifestContent);
        $stream = fopen($archivePath, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('压缩包读取失败');
        }
        try {
            Storage::disk('local')->put($releaseArchivePath, $stream);
        } finally {
            fclose($stream);
        }

        $size = (int) ($archive->getSize() ?: filesize($archivePath) ?: 0);

        $release = DB::transaction(function () use ($component, $version, $platform, $manifestPath, $releaseArchivePath, $sha256, $binarySha256, $size, $makeDefault): ServerMachineRelease {
            $existing = ServerMachineRelease::query()
                ->where('component', $component)
                ->where('version', $version)
                ->where('platform', $platform)
                ->first();
            $hasDefault = ServerMachineRelease::query()
                ->where('component', $component)
                ->where('platform', $platform)
                ->where('status', ServerMachineRelease::STATUS_ACTIVE)
                ->where('is_default', true)
                ->exists();
            $shouldBeDefault = $makeDefault || !$hasDefault || (bool) ($existing?->is_default ?? false);
            if ($shouldBeDefault) {
                ServerMachineRelease::query()
                    ->where('component', $component)
                    ->where('platform', $platform)
                    ->update(['is_default' => false]);
            }

            return ServerMachineRelease::query()->updateOrCreate([
                'component' => $component,
                'version' => $version,
                'platform' => $platform,
            ], [
                'manifest_path' => $manifestPath,
                'archive_path' => $releaseArchivePath,
                'sha256' => $sha256,
                'binary_sha256' => $binarySha256,
                'size' => $size,
                'is_default' => $shouldBeDefault,
                'status' => ServerMachineRelease::STATUS_ACTIVE,
            ]);
        });

        $this->clearLatestReleaseCache();

        return $release->fresh() ?: $release;
    }

    public function setDefaultLocalRelease(ServerMachineRelease $release): ServerMachineRelease
    {
        DB::transaction(function () use ($release): void {
            ServerMachineRelease::query()
                ->where('component', (string) $release->component)
                ->where('platform', (string) $release->platform)
                ->update(['is_default' => false]);

            $release->forceFill([
                'is_default' => true,
                'status' => ServerMachineRelease::STATUS_ACTIVE,
            ])->save();
        });

        $this->clearLatestReleaseCache();

        return $release->fresh() ?: $release;
    }

    public function deleteLocalRelease(ServerMachineRelease $release): void
    {
        if ((bool) $release->is_default) {
            throw new InvalidArgumentException('默认版本不能删除，请先切换默认版本');
        }

        Storage::disk('local')->delete([
            (string) $release->manifest_path,
            (string) $release->archive_path,
        ]);
        $release->delete();
        $this->clearLatestReleaseCache();
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

    private function latestDefaultLocalVersion(string $component, string $platform): ?string
    {
        if (!$this->releaseTableExists()) {
            return null;
        }

        $release = ServerMachineRelease::query()
            ->where('component', $component)
            ->where('platform', $platform)
            ->where('status', ServerMachineRelease::STATUS_ACTIVE)
            ->where('is_default', true)
            ->orderByDesc('id')
            ->first();

        if (!$release) {
            return null;
        }
        if (
            !Storage::disk('local')->exists((string) $release->manifest_path)
            || !Storage::disk('local')->exists((string) $release->archive_path)
        ) {
            return null;
        }

        return (string) $release->version;
    }

    private function manifestComponentMatches(array $manifestData, string $component): bool
    {
        $candidates = [
            data_get($manifestData, 'component'),
            data_get($manifestData, 'name'),
        ];

        foreach ($candidates as $candidate) {
            if (strtolower(trim((string) $candidate)) === $component) {
                return true;
            }
        }

        return false;
    }

    private function releaseTableExists(): bool
    {
        try {
            return Schema::hasTable((new ServerMachineRelease())->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    private function clearLatestReleaseCache(): void
    {
        foreach ([self::SOURCE_GITHUB, self::SOURCE_PANEL, self::SOURCE_CUSTOM] as $source) {
            foreach (['node', 'kelinode-rs', 'core'] as $component) {
                Cache::forget('server_machine:latest_release:' . $source . ':' . $component);
            }
        }
    }
}
