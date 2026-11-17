<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;

/** DNS setup under the same revocable capability and exclusive controller journal. */
final class MemberSetupDns
{
    private array $binding;

    public function __construct(private MemberSetupAuthority $authority, private \Closure $registeredSources,
        private \Closure $load, private \Closure $save)
    {
        $this->binding = $authority->sourceBinding();
    }

    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_setup_dns_' . $code);
    }

    private function credential(): MachineDnsCredential
    {
        $s = $this->binding['scope'];
        $credential = MachineDnsCredential::query()->findOrFail($s['dns_credential_id']);
        self::need($credential->provider === 'aliyun' && $credential->revision === $s['dns_credential_revision'], 'credential_changed');
        return $credential;
    }

    private function providers(array $pins, \Closure $guard): MemberProviderBindings
    {
        $s = $this->binding['scope'];
        $account = MachineCloudAccount::query()->findOrFail($s['account_id']);
        self::need($account->revision === $s['account_revision'], 'account_changed');
        return new MemberProviderBindings(['run_id' => MemberCliAuthority::RUN, 'baseline_ids' => $s['baseline_ids'],
            'pins' => array_map(MemberProviderBindings::digest(...), $pins)], $account->credentials,
            $this->credential()->credentials, $guard, $this->load, $this->save);
    }

    private function within(string $operation, \Closure $callback): array
    {
        return $this->authority->within($operation, 'control', function () use ($operation, $callback): array {
            $shared = app()->isShared(MachineFailoverExecutionGate::class);
            $old = app(MachineFailoverExecutionGate::class);
            $guard = fn () => $this->authority->allow($operation, 'control');
            app()->instance(MachineFailoverExecutionGate::class, new class($guard, $operation) extends MachineFailoverExecutionGate {
                public function __construct(private \Closure $guard, private string $operation) {}
                public function assertAllowed(string $operation): void
                {
                    if (!in_array($operation, ['dns_set_read', 'dns_set_write'], true)
                        || $operation === 'dns_set_write' && !in_array($this->operation, ['dns-control', 'dns-edges'], true))
                        throw new \RuntimeException('member_setup_dns_operation_denied');
                    ($this->guard)();
                }
            });
            try { return $callback($guard); }
            finally {
                if ($shared) app()->instance(MachineFailoverExecutionGate::class, $old);
                else app()->forgetInstance(MachineFailoverExecutionGate::class);
            }
        });
    }

    public function advance(bool $edges): array
    {
        $operation = $edges ? 'dns-edges' : 'dns-control';
        $this->authority->allow($operation, 'control');
        $sources = $edges ? ($this->registeredSources)() : [];
        return $this->within($operation, function (\Closure $guard) use ($edges, $sources): array {
            $records = [];
            if (!$edges) {
                $control = $this->authority->controlBinding();
                $ip = $this->providers([$control['pin']], $guard)->controlAddress($control['pin']);
                self::need($ip === $control['ipv4'], 'control_address_changed');
                $records['control'] = ['value' => $ip,
                    'marker' => 'keli-set-' . substr(MemberProviderBindings::digest($control['pin']), 0, 48)];
            } else {
                $c = $this->binding['configuration'];
                $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($c['service_id'], $c['service_revision']);
                foreach (['source-a' => 'edge-a', 'source-b' => 'edge-b'] as $source => $role) {
                    $pin = $sources[$source]; $task = Task::query()->findOrFail($pin['task_id']);
                    self::need($task->status === 'accepted' && $task->external_id === $pin['external_id']
                        && $task->installation_id === $pin['installation_id'], 'source_changed');
                    $ips = array_values(array_filter($task->public_ips, static fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
                    self::need(count($ips) === 1, 'source_ipv4_required');
                    $member = collect($snapshot['members'])->firstWhere('policy_id', $pin['policy_id']);
                    $set = collect($snapshot['dns_sets'])->firstWhere('hostname', $role . '-' . MemberCliAuthority::RUN . '.dnscloudcloud.top');
                    self::need($member !== null && $set !== null && $set['credential_id'] === $this->credential()->id
                        && $set['credential_revision'] === $this->credential()->revision && count($set['slots']) === 1
                        && $set['slots'][0]['member_id'] === $member['id'] && $set['slots'][0]['type'] === 'A'
                        && $set['slots'][0]['line'] === 'default', 'record_set_changed');
                    $records[$role] = ['value' => $ips[0], 'marker' => $set['slots'][0]['marker']];
                }
            }
            $scope = ['run_id' => MemberCliAuthority::RUN, 'credential_id' => $this->credential()->id,
                'credential_revision' => $this->credential()->revision, 'records' => $records];
            $key = $edges ? 'dns-scope-edges' : 'dns-scope-control';
            $prior = ($this->load)($key);
            self::need($prior === null || $prior === $scope, 'scope_changed');
            ($this->save)($key, $scope);
            self::need(($this->load)($key) === $scope, 'scope_unconfirmed');
            $dns = new MemberDnsInitialization($scope, $this->credential(), $guard, $this->load, $this->save);
            $pins = [];
            foreach (array_keys($records) as $role) $pins[$role] = $dns->advance($role)['pin'];
            return ['ready' => true, 'records' => $pins];
        });
    }

    public function readback(): array
    {
        return $this->within('dns-readback', function (\Closure $guard): array {
            $pins = []; $ready = [];
            foreach (['control', 'edges'] as $kind) {
                $scope = ($this->load)('dns-scope-' . $kind);
                if ($scope === null) continue;
                $dns = new MemberDnsInitialization($scope, $this->credential(), $guard, $this->load, $this->save);
                foreach (array_keys($scope['records']) as $role) {
                    $result = $dns->readback($role);
                    if ($result['pin'] !== null) $pins[$role] = $result['pin'];
                    $ready[$role] = $result['ready'];
                }
            }
            return ['resolved' => true, 'records' => $pins, 'ready' => $ready];
        });
    }

    public function cleanup(): array
    {
        $this->authority->stop();
        return $this->within('dns-cleanup', function (\Closure $guard): array {
            $result = ['complete' => true, 'records' => []];
            foreach (['edge-a', 'edge-b', 'control'] as $role) {
                $scope = ($this->load)('dns-scope-' . ($role === 'control' ? 'control' : 'edges'));
                if ($scope === null) continue;
                $key = null; $state = null;
                try {
                    $dns = new MemberDnsInitialization($scope, $this->credential(), $guard, $this->load, $this->save);
                    $pin = $dns->cleanupReceipt($role);
                    if ($pin === null) continue;
                    $hash = MemberProviderBindings::digest($pin); $key = 'dns-cleanup-' . $hash;
                    $state = ($this->load)($key) ?? ['pin_sha256' => $hash, 'absence_at' => null, 'pending' => false];
                    self::need($state['pin_sha256'] === $hash, 'cleanup_scope_changed');
                    $provider = $this->providers([$pin], $guard);
                    if ($state['pending']) $state['absence_at'] = null;
                    $state['pending'] = true; ($this->save)($key, $state);
                    $remote = $provider->dispatch('dns-inspect', $pin); $now = time();
                    $state['pending'] = false;
                    if ($remote['status'] === 'absent') {
                        $first = $state['absence_at'];
                        if (!is_int($first) || $now < $first) $state['absence_at'] = $now;
                        $complete = is_int($first) && $now >= $first + 10;
                    } else {
                        $state['absence_at'] = null;
                        ($this->save)($key, $state);
                        $provider->dispatch('dns-delete', $pin);
                        $complete = false;
                    }
                    ($this->save)($key, $state);
                    $result['records'][$role] = ['complete' => $complete];
                } catch (\Throwable $error) {
                    if ($state !== null) {
                        $state['absence_at'] = null;
                        try { ($this->save)($key, $state); } catch (\Throwable) { }
                    }
                    $result['records'][$role] = ['complete' => false, 'diagnostic' => [
                        'class' => (new \ReflectionClass($error))->getShortName(), 'file' => basename($error->getFile()), 'line' => $error->getLine()]];
                }
                $result['complete'] = $result['complete'] && $result['records'][$role]['complete'];
            }
            return $result;
        });
    }
}
