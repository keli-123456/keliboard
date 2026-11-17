<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineFailoverPolicy;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineIntentJson;

/** Root-only handoff from initialized sources to the bounded native CLI. */
final class MemberNativeGrant
{
    private const GRANT = '/opt/keli-isolated/private/member-runtime-scope.json';
    private const INTENT = '/opt/keli-isolated/private/member-native-issuance.json';
    public const RUNTIME = ['member_transport_entry.php', 'member_cli_authority.php', 'member_round_bridge.php',
        'member_retry_driver.php', 'member_controller_bridge.php', 'member_source_registry.php', 'member_native_controller.php',
        'member_application_bootstrap.php', 'member_provider_bindings.php', 'member_setup_authority.php',
        'member_candidate_callbacks.php'];
    public const APPLICATION = ['bootstrap/app.php', 'vendor/autoload.php', '.env', 'config/database.php',
        'config/cache.php', 'config/machine_failover.php', 'config/machine_acceptance.php', 'config/machine_provision.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverExecutionGate.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverNativeBridge.php',
        'app/Services/ServerMachine/Cloud/MachineCandidateRetry.php',
        'app/Services/ServerMachine/Cloud/MachineCandidateCleanupClient.php',
        'app/Services/ServerMachine/Cloud/MachineCloudCatalog.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverPolicyService.php'];

    public function __construct(private MemberSetupAuthority $authority, private \Closure $sources,
        private \Closure $dns) {}

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException('member_native_grant_' . $reason);
    }

    private function guard(): void
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0, 'root_cli_required');
        MemberApplicationBootstrap::initialized();
        $this->authority->allow('sources-observe', 'control');
    }

    public static function decision(array $summary, array $source, int $cooldownUntil, int $now): array
    {
        if ($cooldownUntil > $now) return ['ready' => false, 'phase' => 'setup_cooldown', 'next_check_at' => $cooldownUntil];
        $probe = $summary['probe'] ?? [];
        if (($summary['evidence_current'] ?? null) !== true || ($summary['threshold_reached'] ?? null) !== true
            || ($probe['state'] ?? null) !== 'failed')
            return ['ready' => false, 'phase' => 'source_failure_observation'];
        self::need(($probe['installation_id'] ?? null) === ($source['installation_id'] ?? null), 'source_identity_changed');
        $p = $summary['plan'] ?? null;
        self::need(is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0 && ($p['status'] ?? null) === 'blocked'
            && ($p['policy_id'] ?? null) === $source['policy_id'] && ($p['policy_revision'] ?? null) === $source['policy_revision']
            && ($p['evidence']['installation_id'] ?? null) === $source['installation_id']
            && ($p['evidence']['required_failures'] ?? null) === 3
            && is_int($summary['observed_failures'] ?? null) && $summary['observed_failures'] >= 3
            && is_int($p['evidence']['observed_failures'] ?? null) && $p['evidence']['observed_failures'] >= 3,
            'failure_plan_invalid');
        // These informational checks are deferred to the native task, not waived there.
        self::need(is_array($p['blockers'] ?? null), 'failure_plan_invalid');
        $blockers = array_values(array_diff($p['blockers'], ['pricing_unverified', 'execution_disabled',
            'provider_not_checked', 'external_ownership_unverified', 'forwarding_not_verified']));
        if ($blockers) return ['ready' => false, 'phase' => 'source_plan_blocked', 'blockers' => array_map(
            static fn ($code) => is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $code)
                ? $code : 'unknown_blocker', array_slice($blockers, 0, 30))];
        return ['ready' => true, 'plan_id' => $p['id']];
    }

    public function prepare(): array
    {
        $this->guard();
        self::need(!file_exists(self::INTENT) && !is_link(self::INTENT)
            && !file_exists(self::GRANT) && !is_link(self::GRANT), 'issuance_exists');
        $sources = ($this->sources)();
        $dns = ($this->dns)();
        self::need(($dns['resolved'] ?? null) === true && ($dns['ready'] ?? null) === [
            'control' => true, 'edge-a' => true, 'edge-b' => true], 'dns_not_ready');
        $binding = $this->authority->sourceBinding();
        $policy = MachineFailoverPolicy::query()->findOrFail($binding['configuration']['sources']['source-a']['policy_id']);
        $last = MachineProvisionTask::query()->where('account_id', $binding['scope']['account_id'])->max('dispatched_at');
        self::need(is_int($last) && $last > 0, 'dispatch_history_required');
        $summary = $this->authority->within('sources-observe', 'control', function () use ($policy, $binding): array {
            $observer = app(MachineFailoverObserver::class);
            $observer->observe($policy->id);
            $summary = $observer->summary($policy->fresh(), time());
            if (is_array($summary['plan'] ?? null)) {
                $c = $binding['configuration'];
                $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($c['service_id'], $c['service_revision']);
                $legacy = app(\App\Services\ServerMachine\Cloud\MachineFailoverPolicyService::class)->localChecks($policy);
                $native = app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->policyChecks($policy, $legacy, $snapshot);
                $superseded = array_diff(array_column($legacy, 'code'), array_column($native, 'code'));
                $summary['plan']['blockers'] = array_values(array_unique(array_merge(
                    array_diff($summary['plan']['blockers'], $superseded),
                    array_column(array_filter($native, fn ($c) => $c['status'] !== 'passed'), 'code'))));
            }
            return $summary;
        });
        $result = self::decision($summary, $sources['source-a'], $last + $policy->cooldown_seconds, time());
        if (!$result['ready']) return $result;
        return $result + ['sources' => $sources, 'binding' => $binding];
    }

    private static function privateRead(string $path, int $max): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600 && $stat['size'] <= $max, 'private_file_invalid');
        $f = fopen($path, 'rb'); self::need($f !== false, 'private_file_invalid');
        try {
            $opened = fstat($f);
            self::need($opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'private_file_changed');
            $raw = stream_get_contents($f, $max + 1);
            self::need(strlen($raw) === $stat['size'], 'private_file_changed');
        } finally { fclose($f); }
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($data), 'private_file_invalid');
        return $data;
    }

    private function hashes(): array
    {
        $root = MemberCliAuthority::ROOT;
        $receipt = MemberApplicationBootstrap::receipt();
        $publication = self::privateRead($root . '/private/runtime-publication.json', 16777216);
        self::need(($publication['archive_sha256'] ?? null) === $receipt['bundle_sha256']
            && ($publication['manifest']['execution_authorized'] ?? null) === false
            && ($publication['manifest']['credentials_included'] ?? null) === false, 'publication_changed');
        $hashes = [];
        foreach (['runtime' => self::RUNTIME, 'app' => self::APPLICATION] as $kind => $files) {
            foreach ($files as $name) {
                $path = $root . '/' . $kind . '/' . $name; clearstatcache(true, $path); $stat = @lstat($path);
                self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0
                    && $stat['nlink'] === 1 && ($stat['mode'] & 0022) === 0, 'protected_file_changed');
                $expected = $name === '.env' ? $receipt['environment_sha256']
                    : ($publication['manifest']['files'][$kind . '/' . $name]['sha256'] ?? null);
                self::need(is_string($expected) && preg_match('/^[a-f0-9]{64}$/D', $expected) === 1
                    && hash_equals($expected, hash_file('sha256', $path)), 'protected_file_changed');
                $hashes[$kind][$name] = $expected;
            }
        }
        return ['runtime_sha256' => $hashes['runtime'], 'application_sha256' => $hashes['app']];
    }

    private static function publish(string $path, array $value): void
    {
        self::need(!file_exists($path) && !is_link($path), 'issuance_exists');
        $mask = umask(0077);
        try { $f = fopen($path, 'x+b'); } finally { umask($mask); }
        self::need($f !== false, 'publication_failed');
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            self::need(strlen($raw) <= 65536 && chmod($path, 0600) && fwrite($f, $raw) === strlen($raw)
                && fflush($f) && fsync($f), 'publication_failed');
        } finally { fclose($f); }
        self::need(self::privateRead($path, 65536) === $value, 'publication_unconfirmed');
    }

    public function issue(): array
    {
        $prepared = $this->prepare();
        if (!$prepared['ready']) return $prepared;
        $b = $prepared['binding']; $s = $b['scope']; $c = $b['configuration'];
        $pin = MemberApplicationBootstrap::receipt();
        $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($c['service_id'], $c['service_revision']);
        $scope = ['schema' => 2, 'execution_authorized' => true, 'isolated' => true, 'run_id' => MemberCliAuthority::RUN,
            'grant_id' => bin2hex(random_bytes(16)), 'bundle_sha256' => $pin['bundle_sha256'],
            'controller_machine_id_sha256' => $pin['machine_id_sha256'], 'database' => $pin['settings']['database'],
            'driver' => ['run_id' => MemberCliAuthority::RUN, 'workflow' => 'agent_readiness_v1',
                'admin_id' => $s['admin_id'], 'account_id' => $s['account_id'], 'release_id' => $s['release_id'],
                'service_id' => $c['service_id'], 'service_revision' => $c['service_revision'], 'plan_id' => $prepared['plan_id'],
                'policy_a' => $c['sources']['source-a']['policy_id'], 'policy_b' => $c['sources']['source-b']['policy_id'],
                'baseline_ids' => $s['baseline_ids']],
            'sources' => $prepared['sources'], 'control' => $this->authority->controlBinding()['pin'],
            'binary_sha256' => $s['binary_sha256'], 'version' => $s['version'],
            'service_sha256' => hash('sha256', MachineIntentJson::encode($snapshot))]
            + $this->authority->windowBinding() + $this->hashes();
        $this->guard();
        self::publish(self::INTENT, ['scope_sha256' => MemberProviderBindings::digest($scope),
            'setup' => $this->authority->identity()]);
        $journal = MemberCliAuthority::ROOT . '/private/member-journal';
        self::need(!file_exists($journal) && !is_link($journal), 'native_journal_exists');
        self::need(mkdir($journal, 0700) && chmod($journal, 0700), 'native_journal_unavailable');
        $this->guard();
        self::publish(self::GRANT, $scope);
        return $this->readback();
    }

    public function readback(): array
    {
        $this->guard();
        $intent = self::privateRead(self::INTENT, 65536);
        [$scope, $hash] = MemberCliAuthority::readGrant();
        self::need($intent === ['scope_sha256' => MemberProviderBindings::digest($scope),
            'setup' => $this->authority->identity()], 'issuance_changed');
        foreach ($this->hashes() as $key => $value) self::need(($scope[$key] ?? null) === $value, 'protected_file_changed');
        // Lost acknowledgements may publish the same callback derivative, never a new private grant.
        $callback = $this->authority->authorizeCandidates();
        $authority = MemberCliAuthority::controller($scope, $hash, static fn () => false);
        $authority->allow('round-ready'); $authority->assertData();
        self::need($callback['native_sha256'] === $hash, 'callback_binding_changed');
        return ['ready' => true, 'identity' => $authority->identity(), 'deadline_at' => $scope['deadline_at'],
            'cloud_or_dns_writes' => false];
    }
}
