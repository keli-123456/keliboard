<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineFailoverService as Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Operator entry only. Does not issue grants, open the gate or perform provider writes. */
final class MachineUsdo1TimedExecution
{
    private const SERVICE = 1;
    private const NAME = '123';

    public function __construct(private MachineFailoverServiceRegistry $registry,
        private MachineFailoverHandover $handover, private MachineFailoverNativeBridge $native,
        private MachineFailoverRunner $runner, private MachineFailoverExecutionGate $gate) {}

    public function start(string $grantId, int $planId, int $admin): array
    {
        $this->require($planId > 0 && $this->validId($grantId), 'scope_invalid');
        return Cache::lock('machine-usdo1:entry:' . $grantId, 120)->block(2, function () use ($grantId, $planId, $admin) {
            $grant = $this->grant($grantId, $admin);
            if ($grant->execution_id !== null) {
                [$run, $member] = $this->bound($grant);
                $this->require($member->plan_id === $planId, 'intent_changed');
                // An acknowledged or unacknowledged start already committed. Never call native start again.
                return $this->view($grant, $run);
            }

            $this->gate->assertAllowed('usdo1_execution_start');
            $this->require(Service::query()->useWritePdo()->whereKey(self::SERVICE)->value('name') === self::NAME, 'grant_scope');
            $release = $grant->release['id'];
            $this->handover->assertStart($grantId, self::SERVICE, $grant->service_revision, $release, $admin);
            $snapshot = $this->registry->snapshot(self::SERVICE, $grant->service_revision);
            $member = $snapshot['members'][0];
            $plan = Plan::query()->useWritePdo()->find($planId);
            $this->require($plan && $plan->policy_id === $member['policy_id'] && $plan->policy_revision === $member['policy_revision'],
                'plan_changed');
            $this->native->start(self::SERVICE, $grant->service_revision, [$planId], $release, $this->key($grantId, 'start'), $admin,
                handoverGrantId: $grantId);
            $bound = $this->grant($grantId, $admin);
            [$run, $member] = $this->bound($bound);
            $this->require($member->plan_id === $planId, 'intent_changed');
            return $this->view($bound, $run);
        });
    }

    public function status(string $grantId, int $admin): array
    {
        $grant = $this->grant($grantId, $admin);
        return $this->view($grant, $grant->execution_id !== null ? $this->bound($grant)[0] : null);
    }

    public function stop(string $grantId, int $admin): array
    {
        $grant = $this->grant($grantId, $admin);
        if ($grant->execution_id !== null) $this->bound($grant);
        // Native bind and revoke lock the same grant. Re-read in case start committed during the stop request.
        $this->handover->revoke($grantId, $admin);
        $grant = $this->grant($grantId, $admin);
        if ($grant->execution_id === null) return $this->view($grant, null);
        [$run] = $this->bound($grant);
        $runtime = Runtime::query()->useWritePdo()->find($run->id);
        if ($runtime?->stop_requested_at !== null) {
            if ($runtime->state !== 'stopped') $this->runner->settleRequestedStop($run->id, $admin);
        } elseif ($run->status === 'running') {
            $revision = $runtime?->revision ?? 0;
            $this->runner->stop($run->id, $revision, $this->key($grantId, 'stop:' . $revision), $admin);
        }
        return $this->status($grantId, $admin);
    }

    private function grant(string $id, int $admin): Grant
    {
        $this->require($this->validId($id), 'scope_invalid');
        $this->registry->actor($admin);
        $grant = Grant::query()->useWritePdo()->find($id);
        $receipt = $grant?->stop_receipt ?? [];
        $pin = $receipt['manual_usdo1'] ?? null;
        $this->require($grant && $grant->service_id === self::SERVICE && $grant->admin_id === $admin
            && is_array($pin) && is_int($grant->release['id'] ?? null)
            && is_string($receipt['baseline_sha256'] ?? null)
            && is_string($pin['baseline_sha256'] ?? null) && hash_equals($receipt['baseline_sha256'], $pin['baseline_sha256']),
            'grant_scope');
        MachineUsdo1HandoverScope::validate($pin);
        return $grant;
    }

    private function bound(Grant $grant): array
    {
        $run = Execution::query()->useWritePdo()->find($grant->execution_id);
        $snapshot = $run?->payload['snapshot'] ?? null;
        $this->require($run && $run->service_id === self::SERVICE && $run->service_revision === $grant->service_revision
            && $run->admin_id === $grant->admin_id && $run->created_at >= $grant->issued_at && $run->deadline_at <= $grant->expires_at
            && ($run->payload['handover_grant_id'] ?? null) === $grant->id && is_array($snapshot)
            && hash_equals($grant->context_hash, $run->context_hash)
            && hash_equals($grant->context_hash, $this->registry->fingerprint($snapshot))
            && MachineIntentJson::same($run->payload['release'] ?? null, $grant->release)
            && ($run->payload['workflow'] ?? null) === MachineFailoverWorkflow::READINESS
            && hash_equals($run->request_key, hash('sha256', json_encode(
                [$grant->admin_id, $this->key($grant->id, 'start')], JSON_THROW_ON_ERROR))), 'execution_changed');
        $rows = Member::query()->useWritePdo()->where('execution_id', $run->id)->limit(2)->get();
        $members = $snapshot['members'] ?? [];
        $member = $rows->first();
        $this->require($rows->count() === 1 && count($members) === 1
            && ($members[0]['machine_id'] ?? null) === MachineUsdo1HandoverScope::MACHINE
            && $member->member_id === $members[0]['id'] && $member->policy_id === $members[0]['policy_id']
            && $member->policy_revision === $members[0]['policy_revision'], 'execution_changed');
        return [$run, $member];
    }

    private function view(Grant $grant, ?Execution $run): array
    {
        $runtime = $run ? Runtime::query()->useWritePdo()->find($run->id) : null;
        $deadline = $run?->deadline_at ?? $grant->expires_at;
        return ['service_id' => self::SERVICE, 'source_machine_id' => MachineUsdo1HandoverScope::MACHINE,
            'execution_id' => $run?->id, 'status' => $run?->status ?? 'not_started', 'runtime' => $runtime?->state,
            'deadline_at' => $deadline, 'expired' => now()->getTimestamp() >= $deadline,
            'revoked' => $grant->revoked_at !== null, 'released' => $grant->released_at !== null,
            'stop_requested' => $runtime?->stop_requested_at !== null, 'writer_held' => $grant->writer_slot !== null];
    }

    private function key(string $id, string $purpose): string
    {
        return substr(hash('sha256', 'usdo1-timed-execution-v1:' . $id . ':' . $purpose), 0, 32);
    }

    private function validId(string $id): bool
    {
        return Str::isUuid($id) && strtolower($id) === $id;
    }

    private function require(bool $condition, string $reason): void
    {
        if (!$condition) throw new CloudException('usdo1_execution_' . $reason);
    }
}
