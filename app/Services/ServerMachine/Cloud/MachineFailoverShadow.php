<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverPolicy as Policy;
use Illuminate\Support\Facades\Schema;

class MachineFailoverShadow
{
    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverReadOnlyAdapter $adapter) {}

    public function available(): bool
    {
        if (!$this->registry->available() || !app(MachineCloudCredentialHealth::class)->available()) return false;
        foreach (['failover_observation', 'failover_plan', 'owned_instance', 'provision_task', 'replacement_task', 'cloud_task', 'cloud_resource', 'dns_credential'] as $table) {
            if (!Schema::hasTable('v2_machine_' . $table)) return false;
        }
        return true;
    }
    public function report(int $id, int $revision, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->available()) throw new CloudException('shadow_migration_required');
        $snapshot = $this->registry->snapshot($id, $revision); $fingerprint = $this->registry->fingerprint($snapshot);
        $now = now()->getTimestamp(); $validUntil = $now + 30; $members = []; $attempts = 0; $quota = [];
        foreach ($snapshot['members'] as $member) {
            if (now()->getTimestamp() - $now > 10) throw new CloudException('shadow_read_expired');
            $policy = Policy::query()->findOrFail($member['policy_id']);
            $fact = $this->adapter->read($policy, $now, $snapshot); $source = $fact['source']; $blockers = $fact['blockers'];
            if ($source['state'] !== 'unknown' && is_int($source['valid_until'])) $validUntil = min($validUntil, $source['valid_until']);
            $decision = $source['state'] === 'healthy' ? 'healthy' : (!$source['evidence_current'] ? 'insufficient_evidence'
                : (!$source['threshold_reached'] ? 'awaiting_samples' : ($blockers ? 'blocked' : 'would_replace')));
            if ($decision === 'would_replace') {
                $reason = $attempts >= $snapshot['service']['attempt_limit'] ? 'service_attempt_limit'
                    : ($attempts >= $snapshot['service']['max_parallel'] ? 'service_parallel_limit'
                        : (array_intersect($quota, $fact['quota_ids']) ? 'shared_account_wait' : null));
                if ($reason) { $decision = 'waiting'; $blockers[] = $reason; }
                else { $attempts++; $quota = array_values(array_unique([...$quota, ...$fact['quota_ids']])); }
            }
            $members[] = ['member_id' => $member['id'], 'policy_id' => $policy->id, 'policy_revision' => $policy->revision,
                'name' => $member['name'], 'machine_id' => $member['machine_id'], 'decision' => $decision,
                'source' => $source, 'candidate' => $fact['candidate'], 'budget' => $fact['budget'], 'blockers' => $blockers,
                'pending_checks' => ['provider_capacity', 'create_permission', 'candidate_forwarding', 'dns_ownership'],
                'executable' => false];
        }
        $this->registry->actor($admin);
        if ($fingerprint !== $this->registry->fingerprint($this->registry->snapshot($id, $revision))) throw new CloudException('shadow_context_changed');
        $finished = now()->getTimestamp();
        if ($finished - $now > 10 || $validUntil <= $finished) throw new CloudException('shadow_read_expired');
        return ['mode' => 'shadow', 'evidence_origin' => 'panel_cache', 'service_id' => $id, 'service_revision' => $revision,
            'checked_at' => $now, 'valid_until' => $validUntil, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false,
            'dispatch_allowed' => false, 'business_verified' => false, 'cloud_writes' => 0, 'writes_reserved' => false,
            'pricing_checked' => false, 'retained_cost_verified' => false, 'planned_attempts' => $attempts,
            'planned_monthly_reservation_usd' => 0, 'monetary_gate' => false, 'limits' => $snapshot['service'], 'members' => $members];
    }
}
