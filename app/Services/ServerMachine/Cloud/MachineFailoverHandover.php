<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\ServerMachineRelease;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Additional fences for explicitly bound runs; they do not open the production gate.
final class MachineFailoverHandover
{
    public const MAX_SECONDS = 7200;
    private const SLOT = 1;
    private const RELEASE_FIELDS = ['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256'];

    public function __construct(private MachineFailoverServiceRegistry $registry) {}

    /** The trusted caller must have reviewed the old writer's stopped/reconciled evidence. */
    public function issue(array $scope, int $admin): string
    {
        $this->available();
        $keys = ['service_id', 'service_revision', 'context_hash', 'release', 'evidence_sha256', 'expires_at'];
        if (count($scope) !== count($keys) || array_diff(array_keys($scope), $keys)
            || !is_int($scope['service_id']) || $scope['service_id'] < 1
            || !is_int($scope['service_revision']) || $scope['service_revision'] < 1
            || !is_int($scope['expires_at']) || !$this->digest($scope['context_hash']) || !$this->digest($scope['evidence_sha256'])
            || !$this->validRelease($scope['release'])) throw new CloudException('handover_scope_invalid');

        try {
            return $this->registry->locked(fn () => DB::transaction(function () use ($scope, $admin) {
                $this->registry->actor($admin);
                $this->window(now()->getTimestamp(), $scope['expires_at']);
                if (Grant::query()->useWritePdo()->where('evidence_sha256', $scope['evidence_sha256'])->exists())
                    throw new CloudException('handover_evidence_used');
                if (Grant::query()->useWritePdo()->where('writer_slot', self::SLOT)->exists())
                    throw new CloudException('handover_slot_held');

                $grant = new Grant($scope + ['id' => (string) Str::uuid(), 'admin_id' => $admin, 'writer_slot' => self::SLOT]);
                $this->current($grant);
                $this->requireSettled($grant->service_id);
                $grant->issued_at = now()->getTimestamp();
                $this->window($grant->issued_at, $grant->expires_at);
                $grant->save();
                return $grant->id;
            }));
        } catch (UniqueConstraintViolationException) {
            // The unique slot and evidence indexes are the final concurrent-issuance checks.
            throw new CloudException('handover_conflict');
        }
    }

    public function assertStart(string $grantId, int $service, int $revision, int $release, int $admin): int
    {
        return $this->withGrant($grantId, function (Grant $grant) use ($service, $revision, $release, $admin) {
            $this->active($grant);
            if ($grant->service_id !== $service || $grant->service_revision !== $revision || $grant->admin_id !== $admin
                || ($grant->release['id'] ?? null) !== $release) throw new CloudException('handover_scope_changed');
            if ($grant->execution_id !== null) throw new CloudException('handover_already_bound');
            $this->current($grant);
            $this->requireSettled($grant->service_id);
            $this->active($grant);
            return $grant->expires_at;
        });
    }

    /** Native start binds inside its intent transaction, before any enqueue. */
    public function bindExecution(string $grantId, string $executionId): void
    {
        $this->withGrant($grantId, function (Grant $grant) use ($executionId) {
            if ($grant->execution_id !== null && $grant->execution_id !== $executionId)
                throw new CloudException('handover_already_bound');
            $this->readyExecution($grant, $executionId);
            if ($grant->execution_id === null) $grant->fill(['execution_id' => $executionId])->save();
        });
    }

    /** Re-read the grant from the writer for every new action, never from a queued ticket. */
    public function assertNewWork(string $executionId): void
    {
        $this->available();
        $this->uuid($executionId);
        DB::transaction(function () use ($executionId) {
            $grant = Grant::query()->useWritePdo()->where('execution_id', $executionId)->lockForUpdate()->first();
            if (!$grant) throw new CloudException('handover_not_authorized');
            $this->readyExecution($grant, $executionId);
        });
    }

    public function requiredFor(Execution $run): bool
    {
        return array_key_exists('handover_grant_id', $run->payload ?? [])
            || (Schema::hasTable(Grant::TABLE) && Grant::query()->useWritePdo()->where('execution_id', $run->id)->exists());
    }

    public function stoppedOriginal(string $executionId, int $admin): Execution
    {
        $this->available();
        $this->uuid($executionId);
        $this->registry->actor($admin);
        $runtime = Runtime::query()->useWritePdo()->find($executionId);
        $grant = Grant::query()->useWritePdo()->where('execution_id', $executionId)->first();
        $run = Execution::query()->useWritePdo()->find($executionId);
        if (!$grant || $grant->writer_slot !== self::SLOT || $grant->released_at !== null
            || ($grant->revoked_at === null && $grant->expires_at > now()->getTimestamp())
            || !$runtime || $runtime->state !== 'stopped' || !$runtime->stop_requested_at
            || $runtime->lease_until !== null || $runtime->ticket_hash !== null
            || !$run || $run->status !== 'stopped') throw new CloudException('handover_readback_not_stopped');
        $this->assertOriginal($grant, $run);
        return $run;
    }

    public function revoke(string $grantId, int $admin): void
    {
        $this->withGrant($grantId, function (Grant $grant) use ($admin) {
            $this->registry->actor($admin);
            if ($grant->revoked_at === null && $grant->released_at === null)
                $grant->fill(['revoked_at' => now()->getTimestamp(), 'revoked_by' => $admin])->save();
        });
    }

    public function releaseUnused(string $grantId, int $admin): void
    {
        $this->withGrant($grantId, function (Grant $grant) use ($admin) {
            $this->registry->actor($admin);
            if ($grant->released_at !== null) return;
            // Bound executions need explicit stopped reconciliation, not this unused-grant path.
            if ($grant->execution_id !== null || ($grant->revoked_at === null && $grant->expires_at > now()->getTimestamp()))
                throw new CloudException('handover_release_blocked');
            $grant->fill(['writer_slot' => null, 'released_at' => now()->getTimestamp(), 'released_by' => $admin])->save();
        });
    }

    private function withGrant(string $id, callable $work): mixed
    {
        $this->available();
        $this->uuid($id);
        return DB::transaction(function () use ($id, $work) {
            $grant = Grant::query()->useWritePdo()->lockForUpdate()->find($id);
            if (!$grant) throw new CloudException('handover_not_authorized');
            return $work($grant);
        });
    }

    private function readyExecution(Grant $grant, string $id): void
    {
        $this->active($grant);
        $this->uuid($id);
        $run = Execution::query()->useWritePdo()->lockForUpdate()->find($id);
        if (!$run || $run->status !== 'running') throw new CloudException('handover_execution_changed');
        $this->assertOriginal($grant, $run);
        $this->current($grant);
        $this->requireSettled($grant->service_id, $id);
        $this->active($grant);
        if ($run->deadline_at <= now()->getTimestamp()) throw new CloudException('handover_execution_changed');
    }

    private function assertOriginal(Grant $grant, Execution $run): void
    {
        $payload = $run->payload;
        if ($run->service_id !== $grant->service_id
            || $run->service_revision !== $grant->service_revision || $run->admin_id !== $grant->admin_id
            || $run->created_at < $grant->issued_at || $run->deadline_at > $grant->expires_at
            || !hash_equals($grant->context_hash, $run->context_hash)
            || ($payload['handover_grant_id'] ?? null) !== $grant->id
            || ($payload['workflow'] ?? null) !== MachineFailoverWorkflow::READINESS
            || !is_array($payload['snapshot'] ?? null)
            || !hash_equals($grant->context_hash, $this->registry->fingerprint($payload['snapshot']))
            || !MachineIntentJson::same($payload['release'] ?? null, $grant->release)) {
            throw new CloudException('handover_execution_changed');
        }
    }

    private function active(Grant $grant): void
    {
        if ($grant->writer_slot !== self::SLOT || $grant->revoked_at !== null || $grant->released_at !== null)
            throw new CloudException('handover_revoked');
        $now = now()->getTimestamp();
        if ($grant->issued_at > $now || $grant->expires_at <= $now) throw new CloudException('handover_expired');
        $this->window($grant->issued_at, $grant->expires_at);
    }

    private function current(Grant $grant): void
    {
        $this->registry->actor($grant->admin_id);
        $snapshot = $this->registry->snapshot($grant->service_id, $grant->service_revision);
        if (MachineFailoverWorkflow::configured() !== MachineFailoverWorkflow::READINESS
            || !hash_equals($grant->context_hash, $this->registry->fingerprint($snapshot))) throw new CloudException('handover_scope_changed');
        if (!$this->validRelease($grant->release)) throw new CloudException('release_changed');
        $release = ServerMachineRelease::query()->useWritePdo()->find($grant->release['id']);
        if (!$release || $release->status !== ServerMachineRelease::STATUS_ACTIVE
            || !MachineIntentJson::same($release->only(self::RELEASE_FIELDS), $grant->release)) throw new CloudException('release_changed');
    }

    private function requireSettled(int $service, ?string $original = null): void
    {
        // A stopped parent can still contain a child whose provider outcome is unknown.
        $pending = Execution::query()->useWritePdo()->where('service_id', $service)
            ->when($original !== null, fn ($query) => $query->where('id', '!=', $original))
            ->where(fn ($query) => $query->whereIn('status', ['running', 'needs_review'])
                ->orWhereIn('id', Member::query()->where('phase', 'needs_review')->select('execution_id')))->exists();
        if ($pending) throw new CloudException('handover_execution_pending');
    }

    private function validRelease(mixed $release): bool
    {
        return is_array($release) && count($release) === count(self::RELEASE_FIELDS)
            && !array_diff(array_keys($release), self::RELEASE_FIELDS)
            && is_int($release['id']) && $release['id'] > 0 && $release['component'] === 'kelinode-rs'
            && $release['platform'] === 'linux-x86_64' && is_string($release['version'])
            && trim($release['version']) !== '' && strlen($release['version']) <= 120
            && $this->digest($release['sha256']) && $this->digest($release['binary_sha256']);
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function window(int $start, int $end): void
    {
        if ($start <= 0 || $end <= $start || $end - $start > self::MAX_SECONDS) throw new CloudException('handover_window_invalid');
    }

    private function uuid(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) throw new CloudException('handover_not_authorized');
    }

    private function available(): void
    {
        if (!Schema::hasTable(Grant::TABLE)) throw new CloudException('handover_migration_required');
    }
}
