<?php

declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverService;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate as Gate;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover as Handover;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;
use App\Services\ServerMachine\Cloud\MachineUsdo1SingleAExecution;
use App\Services\ServerMachine\Cloud\MachineUsdo1SourceAdmission;
use App\Services\ServerMachine\Cloud\MachineUsdo1TimedExecution as Entry;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\DnsRequestFailure;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport as Transport;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider as DnsContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/usdo1_manual_rollback.php';

/** Assembles real application services with simulated provider lanes. No production launcher. */
final class Usdo1IsolatedExecution
{
    private const SERVICE = 1;
    private const RECOVERY_RESERVE = MachineUsdo1LaunchScope::RECOVERY_SECONDS;
    private const OPS = [
        'admit' => ['usdo1_source_admission'],
        'start' => ['usdo1_execution_start', 'start'],
        'callback' => ['usdo1_execution_callback', 'native_task', 'context'],
        'pulse' => ['usdo1_execution_tick', 'advance', 'context', 'queue', 'native_task', 'node_readiness',
            'native_address_verify', 'native_address_read', 'native_dns_admission', 'native_dns_prepare',
            'native_dns_binding', 'dns_write', 'dns_readback', 'dns_set_start', 'dns_set_advance', 'dns_set_transport',
            'usdo1_single_a_publish'],
    ];
    private array $pin;
    private array $baseline;
    private bool $active = false;

    public function __construct(private int $admin, private int $release, array $source, array $baseline,
        private \Closure $read, private \Closure $sendForward, private \Closure $sendRecovery,
        private \Closure $load, private \Closure $save, private \Closure $sourceHealthy,
        private \Closure $resolversRestored, private \Closure $clock)
    {
        self::isolated();
        $this->baseline = Scope::baseline($baseline);
        $this->pin = Scope::pin($source, $this->baseline);
    }

    public function sourceReview(int $revision): array
    {
        self::isolated();
        self::need(Json::same(Scope::baseline($this->readExact()), $this->baseline), 'baseline_changed');
        return app(MachineUsdo1SourceAdmission::class)->review(self::SERVICE, $revision, $this->release, $this->baseline, $this->admin);
    }

    public function admit(int $revision, array $proof): array
    {
        return $this->lock(function () use ($revision, $proof) {
            $review = $this->sourceReview($revision);
            self::need(Json::same($this->pin, $review['pin']), 'source_changed');
            return $this->within('admit', null, fn () => app(MachineUsdo1SourceAdmission::class)->admit(
                self::SERVICE, $revision, $this->release, $this->baseline, $this->admin, $proof));
        });
    }

    public function start(string $grantId, int $plan): array
    {
        return $this->lock(function () use ($grantId, $plan) {
            $grant = $this->grant($grantId);
            if ($grant->execution_id === null) self::need(Json::same(Scope::baseline($this->readExact()), $this->baseline)
                && now()->timestamp < $grant->expires_at - self::RECOVERY_RESERVE, 'baseline_or_window_changed');
            return $this->within('start', $grant, fn () => app(Entry::class)->start($grantId, $plan, $this->admin));
        });
    }

    public function observeSource(int $revision): array
    {
        return $this->lock(function () use ($revision) {
            $registry = app(MachineFailoverServiceRegistry::class);
            $registry->actor($this->admin);
            $snapshot = $registry->snapshot(self::SERVICE, $revision);
            Scope::assertCurrent($this->pin, $snapshot);
            $original = config('machine_failover.observe_enabled');
            try {
                config(['machine_failover.observe_enabled' => true]);
                return app(MachineFailoverObserver::class)->observe($snapshot['members'][0]['policy_id']);
            } finally {
                config(['machine_failover.observe_enabled' => $original]);
            }
        });
    }

    public function pulse(string $grantId, ?MachineUsdo1LaunchScope $launch = null): array
    {
        return $this->lock(function () use ($grantId, $launch) {
            $grant = $this->grant($grantId);
            self::need($launch === null || ($launch->grant === $grant->id && $launch->admin === $this->admin), 'launch_scope_changed');
            $view = app(Entry::class)->status($grantId, $this->admin);
            if ($view['expired'] || $view['revoked'] || $view['stop_requested'] || $this->cutoverRead($grant)
                || now()->timestamp >= $grant->expires_at - self::RECOVERY_RESERVE)
                return $this->stop($grantId);
            if ($view['execution_id'] === null) throw new CloudException('usdo1_execution_not_started');
            if ($view['status'] !== 'running') return $this->stop($grantId);
            $view = $this->within('pulse', $grant, fn () => $launch
                ? app(MachineUsdo1Runtime::class)->pulse($launch)
                : app(MachineFailoverRunner::class)->pulseUsdo1($grantId, $this->admin));
            return $this->cutoverRead($grant) || $view['status'] !== 'running' ? $this->stop($grantId) : $view;
        });
    }

    public function stop(string $grantId): array
    {
        $this->grant($grantId);
        return app(Entry::class)->stop($grantId, $this->admin);
    }

    public function callback(string $grantId, string $taskId, string $installation, string $secret, string $state,
        ?MachineUsdo1LaunchScope $launch = null): bool
    {
        return $this->lock(function () use ($grantId, $taskId, $installation, $secret, $state, $launch) {
            $grant = $this->grant($grantId);
            self::need($launch === null || ($launch->grant === $grant->id && $launch->admin === $this->admin), 'launch_scope_changed');
            self::need($grant->execution_id !== null, 'callback_scope');
            app(Handover::class)->assertNewWork($grant->execution_id);
            [, , $task] = $this->candidate($grant);
            self::need($task?->id === $taskId && $task->installation_id === $installation, 'callback_scope');
            return $this->within('callback', $grant, fn () => $launch
                ? app(MachineUsdo1Runtime::class)->callback($launch, $taskId, $installation, $secret, $state)
                : app(MachineProvisionService::class)->callback($taskId, $installation, $secret, $state));
        });
    }

    public function closureScope(string $grantId): array
    {
        return $this->lock(fn () => $this->rollbackLane($this->grant($grantId))->closureScope());
    }

    public function rollbackApprovalScope(string $grantId, array $closed): array
    {
        return $this->lock(fn () => $this->rollbackLane($this->grant($grantId))->approvalScope($closed));
    }

    public function rollback(string $grantId, array $closed, array $approval): void
    {
        $this->lock(fn () => $this->rollbackLane($this->grant($grantId))->restore($closed, $approval));
    }

    public function cleanup(string $grantId, array $closed): string
    {
        return $this->lock(function () use ($grantId, $closed) {
            $grant = $this->grant($grantId);
            $rollback = $this->rollbackLane($grant);
            $rollback->confirmClearForCleanup($closed);
            [$member, $attempt, $task] = $this->candidate($grant);
            if (!$task?->dispatched_at) return 'not_dispatched';
            $source = $this->pin['source'];
            $active = MachineOwnedInstance::query()->useWritePdo()->where('active_policy_id', $member->policy_id)->first();
            self::need($task->external_id && $task->external_id !== $source['external_id'] && $task->installation_id !== $source['installation_id']
                && $active?->installation_id === $source['installation_id'] && $active->ip === $source['ipv4']
                && app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task), 'cleanup_identity_unconfirmed');
            $key = $grantId . ':delete';
            $identity = Evidence::hash([$task->id, $task->external_id, $task->installation_id, $task->provider_created_at, $task->account_id, $task->account_revision]);
            $state = ($this->load)($key);
            $client = app(MachineCandidateCleanupClient::class);
            if ($state === null) {
                self::need($client->inspect($task) !== null, 'cleanup_identity_unconfirmed');
                $state = ['identity' => $identity, 'first_absent_at' => null];
                $client->delete($task, function () use ($rollback, $closed, $key, $state) {
                    $rollback->confirmClearForCleanup($closed);
                    $this->persist($key, $state);
                });
            }
            self::need(is_array($state) && count($state) === 2 && ($state['identity'] ?? null) === $identity
                && array_key_exists('first_absent_at', $state)
                && ($state['first_absent_at'] === null || is_int($state['first_absent_at'])),
                'cleanup_journal_changed');
            if ($client->inspect($task) !== null) {
                // A provider-visible instance after a dispatch is unresolved, not permission to re-send.
                if ($state['first_absent_at'] !== null) $this->persist($key, array_replace($state, ['first_absent_at' => null]));
                return 'delete_unconfirmed';
            }
            $now = ($this->clock)();
            if ($state['first_absent_at'] === null) {
                $this->persist($key, array_replace($state, ['first_absent_at' => $now]));
                return 'first_absence';
            }
            return $now >= $state['first_absent_at'] + 10 ? 'absent_confirmed' : 'first_absence';
        });
    }

    private function within(string $role, ?Grant $grant, \Closure $operation): mixed
    {
        self::isolated();
        self::need(!$this->active, 'reentered');
        $app = app();
        $previous = app(Gate::class);
        $transport = $app->bound(Transport::class) ? app(Transport::class) : null;
        $settings = [];
        if ($grant) {
            $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot(self::SERVICE, $grant->service_revision);
            Scope::assertCurrent($this->pin, $snapshot, $grant->execution_id);
            $policy = $snapshot['members'][0]['policy_id'];
            $settings = ['machine_failover.runner_enabled' => true, 'machine_failover.allow_replacement' => true,
                'machine_failover.observe_enabled' => true,
                'machine_failover.allowed_replacement_policy_ids' => [$policy], 'machine_provision.allow_live' => true,
                'machine_provision.allowed_test_machine_ids' => [Scope::MACHINE], 'machine_acceptance.allow_readiness' => true,
                'machine_acceptance.allow_dns' => true, 'machine_acceptance.allowed_policy_ids' => [$policy]];
        }
        $old = [];
        foreach (array_keys($settings) as $key) $old[$key] = config($key);
        $this->active = true;
        try {
            if ($settings) config($settings);
            $app->instance(Gate::class, new class(self::OPS[$role], $grant ? $grant->expires_at - self::RECOVERY_RESERVE : null) extends Gate {
                public function __construct(private array $allowed, private ?int $cutoff) {}
                public function assertAllowed(string $operation): void
                {
                    if (!in_array($operation, $this->allowed, true)) parent::assertAllowed($operation);
                    if ($this->cutoff !== null && now()->timestamp >= $this->cutoff) throw new CloudException('handover_expired');
                }
            });
            if ($role === 'pulse') {
                $app->instance(Transport::class, new class($this->inventory(...), $this->write(...), $grant) extends Transport {
                    public function __construct(private \Closure $read, private \Closure $send, private Grant $grant)
                    { parent::__construct(app(DnsProviderClient::class), app(Gate::class)); }
                    public function inventory(string $run, array $set): array { return ($this->read)($this->grant, $run, $set); }
                    public function write(string $run, array $set, array $action, ?string $knownId = null): string
                    { return ($this->send)($this->grant, $run, $set, $action, $knownId); }
                });
            }
            return $operation();
        } finally {
            if ($settings) config($old);
            $app->instance(Gate::class, $previous);
            if ($transport) $app->instance(Transport::class, $transport);
            else $app->forgetInstance(Transport::class);
            $this->active = false;
        }
    }

    private function inventory(Grant $grant, string $run, array $set): array
    {
        $this->dnsScope($grant, $run, $set);
        if ($run === $grant->execution_id) $this->a($grant)->adopt();
        $row = (new DnsContract)->record($set, $this->readExact());
        return [$row];
    }

    private function write(Grant $grant, string $run, array $set, array $action, ?string $knownId): string
    {
        $parent = $this->dnsScope($grant, $run, $set);
        $child = DnsRun::query()->useWritePdo()->findOrFail($run);
        $binding = app(MachineNativeDnsAdmission::class)->publicationBinding($child);
        $actions = $child->payload['plan']['actions'];
        $pinned = $actions[0];
        $keys = array_flip(['id','kind','member_id','slot_id','before','after','seal']);
        self::need($knownId === null && $pinned['state'] === 'dispatched'
            && Json::same(array_intersect_key($pinned, $keys), array_intersect_key($action, $keys))
            && app(MachineUsdo1SingleAExecution::class)->guard($parent, $child, $binding, true), 'dns_intent_changed');
        app(MachineDnsRecordSetExecution::class)->assertActionJournal($pinned, $set);
        try {
            return $this->a($grant)->cutover($action);
        } catch (\Throwable) {
            if ((($this->load)($grant->id . ':dns')['pending']['step'] ?? null) === 'native-cutover')
                throw new DnsRequestFailure('dns_outcome_unknown');
            throw new CloudException('usdo1_dns_write_unconfirmed');
        }
    }

    private function dnsScope(Grant $grant, string $run, array $set): Execution
    {
        app(MachineFailoverRunner::class)->guard($grant->execution_id);
        $parent = Execution::query()->useWritePdo()->findOrFail($grant->execution_id);
        $original = $parent->payload['snapshot']['dns_sets'][0] ?? [];
        self::need(isset($original['id']) && Json::same(array_intersect_key($original, $set), array_diff_key($set, ['service_id' => true]))
            && ($set['service_id'] ?? self::SERVICE) === self::SERVICE, 'dns_scope_changed');
        [$member, $attempt, $task] = $this->candidate($grant);
        self::need($task && $attempt && $task->dispatched_at && $task->external_id && in_array($task->status, ['awaiting_acceptance','accepted'], true)
            && ($run === $parent->id || ($attempt->payload['dns_set_runs'][$original['id']] ?? null) === $run)
            && ($member->payload['source_instance_id'] ?? null) !== null, 'dns_scope_changed');
        return $parent;
    }

    private function a(Grant $grant): Usdo1ExistingA
    {
        [$run, $record, $source, $marker, $start, $deadline] = $this->lane($grant);
        [$load, $save] = $this->journal($grant);
        return new Usdo1ExistingA($run, $record, $source, $marker, $start, $deadline, $this->read, $this->sendForward,
            function (string $operation) use ($grant): bool {
                app(MachineFailoverRunner::class)->guard($grant->execution_id);
                return in_array($operation, ['claim', 'native-evidence', 'native-cutover'], true);
            }, $load, $save, $this->clock, true);
    }

    private function rollbackLane(Grant $grant): Usdo1ManualRollback
    {
        [$run, $record, , $marker, $start, $deadline] = $this->lane($grant);
        [$load, $save] = $this->journal($grant);
        return new Usdo1ManualRollback($run, $record, $this->pin['source'], $marker, $start, $deadline, app(Evidence::class),
            fn () => app(Handover::class)->closureScope($grant->id, $this->admin), $this->read, $this->sendRecovery,
            $load, $save, $this->sourceHealthy, $this->resolversRestored, $this->clock);
    }

    private function lane(Grant $grant): array
    {
        $parent = Execution::query()->useWritePdo()->findOrFail($grant->execution_id);
        $slot = $parent->payload['snapshot']['dns_sets'][0]['slots'][0];
        return ['keli-accept-usdo1-' . $grant->id, $this->baseline, $this->pin['source']['ipv4'], $slot['marker'], $grant->issued_at, $grant->expires_at];
    }

    private function journal(Grant $grant): array
    {
        $key = $grant->id . ':dns';
        return [fn () => ($this->load)($key), fn (array $data) => $this->persist($key, $data)];
    }

    private function candidate(Grant $grant): array
    {
        $member = Member::query()->useWritePdo()->where('execution_id', $grant->execution_id)->sole();
        $attempts = Attempt::query()->useWritePdo()->where('execution_member_id', $member->id)->limit(2)->get();
        $attempt = $attempts->first();
        self::need($attempts->count() <= 1 && $attempt?->id === $member->attempt_id && (!$attempt || $attempt->ordinal === 1), 'candidate_scope_changed');
        return [$member, $attempt, $attempt?->task_id ? Task::query()->useWritePdo()->findOrFail($attempt->task_id) : null];
    }

    private function cutoverRead(Grant $grant): bool
    {
        if ($grant->execution_id === null) return false;
        if ((($this->load)($grant->id . ':dns')['pending'] ?? null) !== null) return true;
        return DnsRun::query()->useWritePdo()->whereIn('id', array_values($this->candidate($grant)[1]?->payload['dns_set_runs'] ?? []))
            ->whereIn('status', ['completed','needs_review','blocked'])->exists();
    }

    private function grant(string $grantId): Grant
    {
        self::isolated();
        $view = app(Entry::class)->status($grantId, $this->admin);
        $grant = Grant::query()->useWritePdo()->findOrFail($grantId);
        self::need($view['execution_id'] === $grant->execution_id && ($grant->release['id'] ?? null) === $this->release
            && Json::same($grant->stop_receipt['manual_usdo1'] ?? null, $this->pin), 'grant_changed');
        return $grant;
    }

    private function persist(string $key, array $state): void
    {
        ($this->save)($key, $state);
        self::need(Json::same(($this->load)($key), $state), 'journal_unconfirmed');
    }

    private function readExact(): array
    {
        $rows = ($this->read)(Scope::NAME, $this->pin['record_id']);
        self::need(array_is_list($rows) && count($rows) === 1 && ($rows[0]['RecordId'] ?? null) === $this->pin['record_id']
            && is_string($rows[0]['Remark'] ?? null), 'dns_inventory_changed');
        return $rows[0];
    }

    private function lock(\Closure $work): mixed
    {
        self::isolated();
        self::need(MachineFailoverService::query()->useWritePdo()->whereKey(self::SERVICE)->value('name') === '123', 'service_changed');
        return Cache::lock('usdo1-isolated-writer:' . $this->pin['record_id'], 120)->block(2, $work);
    }

    private static function isolated(): void
    {
        $db = app('db')->connection();
        $memory = PHP_SAPI === 'cli' && $db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:';
        $ci = false;
        if (!$memory && PHP_OS_FAMILY === 'Linux' && in_array(PHP_SAPI, ['cli','cli-server'], true) && app()->runningUnitTests()
            && getenv('GITHUB_ACTIONS') === 'true' && getenv('KELI_USDO1_ACCEPTANCE') === '1'
            && getenv('KELI_NATIVE_ACCEPTANCE') === '1' && $db->getDriverName() === 'mysql'
            && $db->getDatabaseName() === 'keli_acceptance_native_failover'
            && $db->getConfig('host') === '127.0.0.1' && $db->getConfig('username') === 'keli_native_fixture'
            && (int) $db->getConfig('port') === (int) getenv('KELI_NATIVE_MYSQL_PORT')) {
            $owner = (string) getenv('KELI_USDO1_FIXTURE_OWNER');
            $ci = preg_match('/^[a-f0-9]{32}$/D', $owner) === 1
                && config('database.redis.options.prefix') === 'native-fixture:' . $owner . ':'
                && config('database.redis.default.host') === '127.0.0.1'
                && (int) config('database.redis.default.port') === (int) getenv('KELI_NATIVE_REDIS_PORT')
                && DB::table('native_fixture_owner')->value('owner') === $owner
                && app('redis')->connection()->get('owner') === $owner;
        }
        self::need(app()->runningUnitTests() && ($memory || $ci)
            && config('machine_failover.allow_rotation') === false && config('machine_failover.allow_candidate_retry') === false
            && config('machine_failover.admission_enabled') === false, 'isolated_environment_required');
        Http::preventStrayRequests();
    }

    private static function need(bool $allowed, string $reason): void
    {
        if (!$allowed) throw new CloudException('usdo1_isolated_' . $reason);
    }
}
