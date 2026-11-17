<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Keli\Acceptance\MemberApplicationBootstrap;
use Keli\Acceptance\MemberCliAuthority;
use Keli\Acceptance\MemberPrerequisiteImport;
use Keli\Acceptance\MemberProviderBindings;

return static function (string $mode): array {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1') throw new RuntimeException('fixture_only');
    $need = static function (bool $ok): void { if (!$ok) throw new RuntimeException('fixture_import_invalid'); };
    if ($mode === 'import-service-read') {
        $need(posix_geteuid() === 65534);
        $release = App\Models\ServerMachineRelease::query()->sole();
        $need(!$release->is_default && hash_file('sha256', Storage::disk('local')->path($release->archive_path)) === $release->sha256);
        $need(hash_file('sha256', Storage::disk('local')->path($release->manifest_path)) === hash_file('sha256', '/opt/keli-isolated/node/release.json'));
        $need(!is_readable('/opt/keli-isolated/private/credentials') && !is_writable(Storage::disk('local')->path($release->archive_path)));
        return ['published_files_readable_not_writable' => true, 'private_credentials_unreadable' => true];
    }
    MemberApplicationBootstrap::initialized();
    require_once '/opt/keli-isolated/runtime/member_provider_bindings.php';
    require_once '/opt/keli-isolated/runtime/member_prerequisite_import.php';
    Http::preventStrayRequests();
    Http::fake(fn () => throw new RuntimeException('fixture_import_network_forbidden'));
    $tables = ['v2_user', 'v2_machine_cloud_group', 'v2_machine_cloud_account', 'v2_machine_dns_credential', 'v2_server_machine_release'];
    $counts = static fn () => array_map(fn ($table) => DB::table($table)->count(), $tables);
    if ($mode === 'import-failures') {
        $need(posix_geteuid() === 0 && $counts() === array_fill(0, 5, 0));
        $root = '/opt/keli-isolated/private/import-failure-fixture'; $need(!file_exists($root) && mkdir($root, 0700));
        foreach (['import-admin', 'import-account', 'import-dns', 'import-release', 'import-complete'] as $index => $phase) {
            $state = [];
            // Keep each failed attempt's evidence separate; never erase or replay its intent.
            $load = static function ($key) use (&$state) { return $state[$key] ?? null; };
            $save = static function ($key, $value) use (&$state, $root, $index): void {
                $state[$key] = $value;
                $path = $root . '/' . $index . '-' . $key . '.json';
                $stream = fopen($path, 'x+b');
                if (!$stream) throw new RuntimeException('fixture_import_journal');
                try { chmod($path, 0600); fwrite($stream, json_encode($value, JSON_THROW_ON_ERROR)); fflush($stream); fsync($stream); }
                finally { fclose($stream); }
            };
            $guard = static function ($operation) use ($phase): void {
                if ($operation === $phase) throw new RuntimeException('synthetic-private-detail-not-for-output');
            };
            $importer = new MemberPrerequisiteImport($guard, $load, $save);
            try { $importer->import(); throw new LogicException('fixture_import_injection_missing'); }
            catch (RuntimeException $error) { $need($error->getMessage() === 'synthetic-private-detail-not-for-output'); }
            $need($counts() === array_fill(0, 5, 0));
            $first = $state['first-error'];
            $need(array_keys($first) === ['class', 'file', 'line'] && $first['class'] === 'RuntimeException');
            try { $importer->import(); throw new LogicException('fixture_import_uncertain_replayed'); }
            catch (RuntimeException $error) { $need($error->getMessage() === 'member_import_result_unconfirmed'); }
            $need($state['first-error'] === $first);
            $releaseRoot = Storage::disk('local')->path('kelinode-rs/releases');
            if (file_exists($releaseRoot)) {
                $need(realpath($releaseRoot) === '/opt/keli-isolated/app/storage/app/kelinode-rs/releases');
                $need(Storage::disk('local')->deleteDirectory('kelinode-rs/releases'));
            }
        }
        return ['rollback_phases' => 5, 'first_error_retained' => true, 'uncertain_replay_refused' => true,
            'rows' => $counts(), 'provider_requests' => 0];
    }
    if ($mode === 'import-inspect') {
        $need($counts() === array_fill(0, 5, 1));
        $account = App\Models\MachineCloudAccount::query()->sole();
        $dns = App\Models\MachineDnsCredential::query()->sole();
        $need(!str_contains(DB::table('v2_machine_cloud_account')->value('credentials'), $account->credentials['api_token'])
            && !str_contains(DB::table('v2_machine_dns_credential')->value('credentials'), $dns->credentials['access_key_secret']));
        $need(!App\Models\ServerMachineRelease::query()->where('is_default', true)->exists());
        foreach (MemberCliAuthority::closed() as $key => $value) $need(config($key) === $value);
        return ['credential_storage_encrypted' => true, 'default_release_unset' => true,
            'gates_closed' => true, 'rows' => $counts(), 'provider_requests' => 0];
    }
    throw new RuntimeException('fixture_import_mode_invalid');
};
