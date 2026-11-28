<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

final class MachineFailoverOperator
{
    private const PURPOSE = 'native_failover_start_v1';

    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverShadow $shadow,
        private MachineFailoverTriggerPreview $plans, private MachineFailoverNativeBridge $native, private MachineFailoverRunner $runner,
        private MachineFailoverHandover $handover) {}

    public function preview(int $service, int $revision, int $admin): array
    {
        [$view, $scope] = $this->inspect($service, $revision, $admin);
        if ($scope === null) return $view + ['request_id' => null, 'confirmation' => null];

        $request = bin2hex(random_bytes(16));
        $token = ['purpose' => self::PURPOSE, 'admin' => $admin, 'service' => $service, 'revision' => $revision,
            'request' => $request, 'expires_at' => $view['valid_until'], 'scope' => $scope];
        return $view + ['request_id' => $request, 'confirmation' => Crypt::encryptString(json_encode($token, JSON_THROW_ON_ERROR))];
    }

    public function start(int $service, string $confirmation, int $admin): array
    {
        $this->registry->actor($admin);
        try {
            $token = json_decode(Crypt::decryptString($confirmation), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException) {
            throw new CloudException('operator_confirmation_invalid');
        }
        if (!is_array($token) || ($token['purpose'] ?? null) !== self::PURPOSE || ($token['admin'] ?? null) !== $admin
            || ($token['service'] ?? null) !== $service || !is_int($token['revision'] ?? null)
            || !is_int($token['expires_at'] ?? null) || !is_array($token['scope'] ?? null)
            || !is_string($token['request'] ?? null)) throw new CloudException('operator_confirmation_invalid');

        $request = $token['request'];
        $existing = $this->receipt($service, $request, $admin);
        if ($existing['execution_id'] !== null) return $existing;
        if ($token['expires_at'] <= now()->getTimestamp()) throw new CloudException('operator_confirmation_expired');

        try {
            [$view, $scope] = $this->inspect($service, $token['revision'], $admin);
            if ($scope === null) throw new CloudException($view['blocker']);
            if ($scope !== $token['scope']) throw new CloudException('operator_scope_changed');
            if ($token['expires_at'] <= now()->getTimestamp()) throw new CloudException('operator_confirmation_expired');
            $result = MachineFailoverOperatorPermit::start($scope['grant_id'], $service, $token['revision'], $scope['release_id'], $admin,
                $scope['plan_ids'], fn () => $this->native->start($service, $token['revision'], $scope['plan_ids'], $scope['release_id'],
                    $request, $admin, handoverGrantId: $scope['grant_id']));
        } catch (CloudException $error) {
            // A concurrent submission can bind this grant before the second check finishes.
            $existing = $this->receipt($service, $request, $admin);
            if ($existing['execution_id'] !== null) return $existing;
            throw $error;
        }
        return ['execution_id' => $result['id'], 'status' => $result['status']];
    }

    public function receipt(int $service, string $request, int $admin): array
    {
        $this->registry->actor($admin);
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_request_id');
        if (!Schema::hasTable('v2_machine_failover_execution')) throw new CloudException('runtime_migration_required');
        $run = Execution::query()->useWritePdo()->where('admin_id', $admin)->where('service_id', $service)
            ->where('request_key', hash('sha256', json_encode([$admin, $request], JSON_THROW_ON_ERROR)))
            ->first(['id', 'status']);
        return ['execution_id' => $run?->id, 'status' => $run?->status];
    }

    private function inspect(int $service, int $revision, int $admin): array
    {
        $report = $this->shadow->report($service, $revision, $admin);
        $snapshot = $this->registry->snapshot($service, $revision);
        $view = ['service_id' => $service, 'service_revision' => $revision, 'valid_until' => $report['valid_until'],
            'members' => [], 'blocker' => null];
        $ids = [];
        $candidates = array_column($snapshot['members'], null, 'id');
        $attempts = Schema::hasTable('v2_machine_failover_execution_member');
        foreach ($report['members'] as $member) {
            $id = $member['member_id'];
            $decision = $member['decision'];
            $reason = $member['blockers'][0] ?? null;
            if ($decision === 'would_replace') {
                $plan = $this->plans->qualifiedPlan($member);
                if (MachineFailoverExistingSource::isDraftSlot($candidates[$id]['policy']['slot'] ?? '')) {
                    $decision = 'blocked'; $reason = 'existing_source_draft_only';
                } elseif (!$plan) {
                    $decision = 'blocked'; $reason = 'operator_plan_missing';
                } elseif ($attempts && Member::query()->useWritePdo()->where('episode_key', $plan->evidence['episode_key'])->exists()) {
                    $decision = 'blocked'; $reason = 'replacement_episode_used';
                } else {
                    $ids[$member['policy_id']] = $plan->id;
                }
            }
            $view['members'][] = ['id' => $id, 'name' => $member['name'], 'decision' => $decision, 'reason' => $reason];
        }

        try {
            MachineFailoverOperatorPermit::requireEnabled();
            if (!$this->runner->available() || !$this->native->available() || !Schema::hasTable(Grant::TABLE))
                throw new CloudException('runtime_migration_required');
            if (Execution::query()->useWritePdo()->where('service_id', $service)->where('status', 'running')->exists())
                throw new CloudException('run_active');
            foreach ($candidates as $member) {
                if (MachineFailoverExistingSource::isDraftSlot($member['policy']['slot'])) throw new CloudException('existing_source_draft_only');
            }
            if (!$ids) throw new CloudException('operator_no_eligible_members');
            if (count($ids) > 10) throw new CloudException('invalid_native_intent');
            $grant = Grant::query()->useWritePdo()->where('service_id', $service)->where('service_revision', $revision)
                ->where('admin_id', $admin)->whereNotNull('writer_slot')->whereNull('execution_id')->first();
            if (!MachineFailoverOperatorPermit::issuedForOperator($grant)) throw new CloudException('handover_not_authorized');
            $release = $grant->release['id'] ?? 0;
            if (!is_int($release) || $release < 1) throw new CloudException('release_unavailable');
            $expiry = $this->handover->assertStart($grant->id, $service, $revision, $release, $admin);
            $context = $this->registry->fingerprint($snapshot);
            if (!hash_equals($grant->context_hash, $context)) throw new CloudException('handover_scope_changed');
            MachineFailoverOperatorPermit::requireConsumer();
            $view['valid_until'] = min($view['valid_until'], $expiry);
            if ($view['valid_until'] <= now()->getTimestamp()) throw new CloudException('shadow_read_expired');
            $ids = array_values($ids);
            sort($ids);
            return [$view, ['context' => $context, 'plan_ids' => $ids, 'grant_id' => $grant->id, 'release_id' => $release]];
        } catch (CloudException $error) {
            $view['blocker'] = $error->getMessage();
            return [$view, null];
        }
    }
}
