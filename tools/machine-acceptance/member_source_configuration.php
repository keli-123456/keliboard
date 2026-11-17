<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverPolicy;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Cloud\MachineCloudCatalog;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineProvisionLimits;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use Illuminate\Support\Facades\DB;

/** Fresh isolated configuration only. Caller holds the exclusive journal lock. */
final class MemberSourceConfiguration
{
    private const EMPTY = ['v2_server_machine', 'v2_server', 'v2_machine_failover_policy',
        'v2_machine_failover_service', 'v2_machine_failover_member', 'v2_machine_failover_dns_set',
        'v2_machine_failover_dns_slot', 'v2_machine_provision_task', 'v2_machine_owned_instance',
        'v2_machine_failover_execution', 'v2_machine_cloud_task', 'v2_machine_owned_dns',
        'v2_machine_cloud_resource', 'v2_machine_dns_binding'];

    public function __construct(private array $scope, private \Closure $guard,
        private \Closure $load, private \Closure $save)
    {
        $keys = ['run_id', 'admin_id', 'account_id', 'account_revision', 'group_id', 'dns_credential_id',
            'dns_credential_revision', 'release_id', 'binary_sha256', 'version', 'baseline_ids'];
        self::need(count($scope) === count($keys) && !array_diff(array_keys($scope), $keys)
            && ($scope['run_id'] ?? null) === MemberProviderBindings::RUN, 'scope_invalid');
        foreach (['admin_id', 'account_id', 'account_revision', 'group_id', 'dns_credential_id', 'dns_credential_revision', 'release_id'] as $key)
            self::need(is_int($scope[$key]) && $scope[$key] > 0, 'scope_invalid');
        self::need(is_string($scope['binary_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $scope['binary_sha256'])
            && is_string($scope['version']) && preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $scope['version'])
            && is_array($scope['baseline_ids']) && array_is_list($scope['baseline_ids']) && count($scope['baseline_ids']) <= 996
            && count(array_unique($scope['baseline_ids'], SORT_REGULAR)) === count($scope['baseline_ids']), 'scope_invalid');
        foreach ($scope['baseline_ids'] as $id)
            self::need(is_string($id) && preg_match('/^[1-9][0-9]{0,15}$/D', $id), 'scope_invalid');
    }

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_configuration_' . $code);
    }

    private function context(string $operation): array
    {
        // This cannot be used against the normal application, including a local
        // copy pointed at its database. Check isolation before the first query.
        ($this->guard)($operation);
        MemberApplicationBootstrap::initialized();
        $s = $this->scope;
        app(MachineFailoverServiceRegistry::class)->actor($s['admin_id']);
        $account = MachineCloudAccount::query()->findOrFail($s['account_id']);
        $dns = MachineDnsCredential::query()->findOrFail($s['dns_credential_id']);
        $release = ServerMachineRelease::query()->findOrFail($s['release_id']);
        self::need($account->provider === 'linode' && $account->revision === $s['account_revision']
            && $account->group_id === $s['group_id'] && MachineCloudAccount::query()->count() === 1
            && $dns->provider === 'aliyun' && $dns->revision === $s['dns_credential_revision'], 'credentials_changed');
        $limits = app(MachineProvisionLimits::class)->settings($account);
        self::need($limits === ['max_instances' => count($s['baseline_ids']) + 4, 'max_parallel' => 1, 'daily_limit' => 5], 'limits_changed');
        self::need($release->component === 'kelinode-rs' && $release->platform === 'linux-x86_64' && $release->status === 'active'
            && $release->binary_sha256 === $s['binary_sha256'] && $release->version === $s['version'], 'release_changed');
        return [$account, $dns, $release];
    }

    private function persist(string $key, array $value): void
    {
        ($this->save)($key, $value);
        $observed = ($this->load)($key);
        self::need(is_array($observed) && MemberProviderBindings::digest($observed) === MemberProviderBindings::digest($value),
            'journal_unconfirmed');
    }

    private static function nodePin(Server $node): array
    {
        return $node->only(['machine_id', 'type', 'runtime', 'enabled', 'show', 'host', 'port',
            'server_port', 'group_ids', 'route_ids', 'protocol_settings']);
    }

    public function create(): array
    {
        [$account] = $this->context('source-configure');
        $binding = ['scope_sha256' => MemberProviderBindings::digest($this->scope)];
        $prior = ($this->load)('source-configuration-intent');
        if ($prior !== null) {
            self::need($prior === $binding, 'scope_changed');
            return $this->readback();
        }
        foreach (self::EMPTY as $table) self::need(!DB::table($table)->exists(), 'fresh_database_required');
        $this->persist('source-configuration-intent', $binding);
        try {
            // Read-only provider calls use the existing health and catalog services.
            app(MachineCloudCredentialHealth::class)->verify($account->id, $account->revision, $this->scope['admin_id']);
            $catalog = app(MachineCloudCatalog::class)->read($this->scope['group_id'], 'linode', true);
            $result = DB::transaction(function () use ($catalog): array {
                $this->context('source-configure-commit');
                foreach (self::EMPTY as $table) self::need(!DB::table($table)->exists(), 'fresh_database_required');
                $sources = []; $members = [];
                foreach (['a', 'b'] as $index => $letter) {
                    ($this->guard)('source-configure-' . $letter);
                    $machine = ServerMachine::query()->create(['name' => MemberProviderBindings::RUN . '-source-' . $letter,
                        'token' => ServerMachine::generateToken(), 'is_active' => true]);
                    $hostname = 'edge-' . $letter . '-' . MemberProviderBindings::RUN . '.dnscloudcloud.top';
                    $node = Server::query()->create(['name' => 'R35 source ' . strtoupper($letter), 'machine_id' => $machine->id,
                        'type' => 'hysteria', 'runtime' => 'generic', 'enabled' => true, 'show' => false, 'rate' => 1,
                        'host' => $hostname, 'port' => '24443', 'server_port' => 24443, 'group_ids' => [], 'route_ids' => [],
                        'protocol_settings' => ['version' => 2]]);
                    $policy = app(MachineFailoverPolicyService::class)->save(['name' => 'R35 member ' . strtoupper($letter),
                        'machine_id' => $machine->id, 'provider' => 'linode', 'credential_group_id' => $this->scope['group_id'],
                        'catalog_fingerprint' => $catalog['fingerprint'], 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
                        'image' => 'linode/debian12', 'failure_threshold' => 3, 'stale_after_seconds' => 300,
                        'cooldown_seconds' => 300, 'daily_limit' => $letter === 'a' ? 3 : 1, 'isolation_confirmed' => true],
                        $this->scope['admin_id']);
                    $sources['source-' . $letter] = ['machine_id' => $machine->id, 'node_id' => $node->id,
                        'policy_id' => $policy['id'], 'policy_revision' => $policy['revision'],
                        'node_sha256' => MemberProviderBindings::digest(self::nodePin($node->fresh())),
                        'plan' => ProvisionPlan::normalize(app(MachineFailoverCredentials::class)->context(
                            MachineFailoverPolicy::query()->findOrFail($policy['id'])) + [
                            'machine_id' => $machine->id, 'slot' => $policy['slot'], 'panel_origin' => config('app.url'),
                            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'authorized_keys' => []]),
                        'configured_nodes' => [['id' => $node->id, 'protocol' => 'hysteria2', 'port' => 24443]]];
                    $members[] = ['policy_id' => $policy['id'], 'policy_revision' => $policy['revision'],
                        'name' => strtoupper($letter), 'priority' => $index + 1, 'enabled' => true, 'attempt_limit' => 2];
                }
                ($this->guard)('source-configure-service');
                $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'R35 isolated members',
                    'max_parallel' => 1, 'attempt_limit' => 2, 'members' => $members], $this->scope['admin_id']);
                foreach (['a', 'b'] as $index => $letter) {
                    $member = collect($service['members'])->firstWhere('policy_id', $sources['source-' . $letter]['policy_id']);
                    ($this->guard)('source-configure-dns-' . $letter);
                    $saved = app(MachineFailoverDnsRegistry::class)->save(['service_id' => $service['id'],
                        'service_revision' => $service['revision'] + $index, 'credential_id' => $this->scope['dns_credential_id'],
                        'credential_revision' => $this->scope['dns_credential_revision'], 'zone' => 'dnscloudcloud.top',
                        'zone_name' => 'dnscloudcloud.top', 'hostname' => 'edge-' . $letter . '-' . MemberProviderBindings::RUN . '.dnscloudcloud.top',
                        'ttl' => 600, 'members' => [['member_id' => $member['id'], 'types' => ['A'], 'lines' => ['default']]]],
                        $this->scope['admin_id']);
                }
                $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($service['id'], $saved['service_revision']);
                ($this->guard)('source-configure-complete');
                return ['service_id' => $service['id'], 'service_revision' => $saved['service_revision'], 'sources' => $sources,
                    'service_sha256' => MemberProviderBindings::digest($snapshot)];
            });
            $this->persist('source-configuration-result', $binding + $result);
            return $this->readback();
        } catch (\Throwable $error) {
            try {
                if (($this->load)('source-configuration-first-error') === null)
                    $this->persist('source-configuration-first-error', ['class' => (new \ReflectionClass($error))->getShortName(),
                        'file' => basename($error->getFile()), 'line' => $error->getLine()]);
            } catch (\Throwable) { /* Retain the initiating failure. */ }
            throw $error;
        }
    }

    public function readback(): array
    {
        $this->context('source-configuration-read');
        $result = ($this->load)('source-configuration-result');
        $binding = ['scope_sha256' => MemberProviderBindings::digest($this->scope)];
        self::need(($this->load)('source-configuration-intent') === $binding && is_array($result)
            && ($result['scope_sha256'] ?? null) === $binding['scope_sha256'], 'result_unconfirmed');
        $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($result['service_id'], $result['service_revision']);
        self::need(MemberProviderBindings::digest($snapshot) === $result['service_sha256'], 'configuration_changed');
        $sources = [];
        foreach ($result['sources'] as $role => $pin) {
            $policy = MachineFailoverPolicy::query()->findOrFail($pin['policy_id']);
            $node = Server::query()->findOrFail($pin['node_id']);
            self::need(MemberProviderBindings::digest(self::nodePin($node)) === $pin['node_sha256'], 'node_changed');
            self::need($policy->failure_threshold === 3 && $policy->cooldown_seconds === 300
                && $policy->stale_after_seconds === 300 && $policy->daily_limit === ($role === 'source-a' ? 3 : 1),
                'policy_changed');
            // Recovery reads the original choice, even while A owns the create lane.
            // The native dispatch service still enforces live capacity before mutation.
            $plan = ProvisionPlan::normalize($pin['plan']);
            self::need(MemberProviderBindings::digest($plan) === MemberProviderBindings::digest($pin['plan'])
                && $plan['account_id'] === $this->scope['account_id']
                && $plan['machine_id'] === $policy->machine_id && $plan['slot'] === $policy->slot
                && $plan['panel_origin'] === config('app.url') && $plan['region'] === $policy->region
                && $plan['size'] === $policy->size && $plan['image'] === $policy->image
                && $plan['provider'] === 'linode' && $plan['authorized_keys'] === []
                && $plan['credential_pool']['group_id'] === $this->scope['group_id']
                && $plan['credential_pool']['account_revision'] === $this->scope['account_revision'], 'plan_changed');
            $sources[$role] = ['policy_id' => $policy->id, 'policy_revision' => $policy->revision,
                'configured_nodes' => $pin['configured_nodes'], 'plan' => $plan];
        }
        return $result + ['initialization_scope' => ['run_id' => $this->scope['run_id'], 'admin_id' => $this->scope['admin_id'],
            'release_id' => $this->scope['release_id'], 'baseline_ids' => $this->scope['baseline_ids'],
            'controller_machine_id_sha256' => hash_file('sha256', '/etc/machine-id'), 'sources' => $sources],
            'health_or_forwarding_claimed' => false, 'cloud_or_dns_writes' => false];
    }
}
