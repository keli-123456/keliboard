<?php

namespace App\Services\ServerMachine;

use App\Jobs\SendMachineOperationNoticeJob;
use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineDnsBinding;
use App\Models\MachineOperationNotice as Notice;
use App\Models\MachineReplacementTask;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\MachineReplacementService;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineOperationNotifier
{
    private const POLICY = 'v2_machine_operation_notice_policy';
    private const DNS_FAILURES = ['source_unavailable', 'cloud_recovery_pending', 'identity_changed', 'record_changed',
        'record_mismatch', 'record_locked', 'credentials_required', 'provider_unavailable', 'provider_forbidden',
        'provider_rate_limited', 'provider_rejected', 'verification_failed', 'sync_failed'];

    public function available(): bool
    {
        return Schema::hasTable(self::POLICY) && Schema::hasTable('v2_machine_operation_notice')
            && Schema::hasColumn('v2_machine_dns_binding', 'notice_episode');
    }

    public function overview(string $scope, ?int $resource = null): array
    {
        $this->scope($scope);
        if (!$this->available() || !$this->scopeAvailable($scope)) return ['available' => false, 'policy' => ['enabled' => false, 'revision' => 0], 'channel_ready' => false, 'notices' => []];
        $policy = DB::table(self::POLICY)->where('scope', $scope)->first();
        return ['available' => true, 'policy' => ['enabled' => (bool) $policy->enabled, 'revision' => (int) $policy->revision],
            'channel_ready' => $this->channelReady(), 'notices' => Notice::query()->where('scope', $scope)
                ->when($scope === 'cloud' && $resource !== null, fn($query) => $query->where('resource_id', $resource))
                ->orderByDesc('id')->limit(100)->get()];
    }

    public function save(string $scope, bool $enabled, int $revision, int $admin): array
    {
        $this->scope($scope);
        if (!$this->available() || !$this->scopeAvailable($scope)) throw new MachineOperationNoticeException('migration_required');
        if (!$this->adminActive($admin)) throw new MachineOperationNoticeException('permission_revoked');
        DB::transaction(function () use ($scope, $enabled, $revision, $admin) {
            $row = DB::table(self::POLICY)->where('scope', $scope)->lockForUpdate()->first();
            if (!$row || (int) $row->revision !== $revision) throw new MachineOperationNoticeException('policy_changed');
            DB::table(self::POLICY)->where('scope', $scope)->update(['enabled' => $enabled, 'revision' => $revision + 1,
                'updated_by' => $admin, 'updated_at' => time()]);
            Notice::query()->where('scope', $scope)->where('status', 'pending')->update(['status' => 'suppressed', 'delivery_reason' => 'policy_changed']);
        });
        return $this->overview($scope);
    }

    public function dns(int $id): void
    {
        $this->safely(function () use ($id) {
            $this->locked('dns', $id, fn() => DB::transaction(function () use ($id) {
                $binding = MachineDnsBinding::query()->find($id);
                if (!$binding) return;
                if ($binding->status === 'synced') {
                    if (!$binding->notice_episode) return;
                    $failure = Notice::query()->where('scope', 'dns')->where('source_id', $id)->where('episode', $binding->notice_episode)->where('phase', 'failed')->first();
                    $episode = $binding->notice_episode;
                    $binding->notice_episode = null; $binding->save();
                    $this->record('dns', $id, null, $episode, 'recovered', $this->dnsContext($binding), $this->dnsSnapshot($binding),
                        $failure?->status === 'submitted' ? null : 'no_prior_notice');
                    return;
                }
                if (!in_array($binding->status, self::DNS_FAILURES, true) || $binding->notice_episode) return;
                $binding->notice_episode = (string) Str::uuid(); $binding->save();
                $notice = $this->record('dns', $id, null, $binding->notice_episode, 'failed', $this->dnsContext($binding),
                    $this->dnsSnapshot($binding), ($binding->notice_last_alert_at ?? 0) > time() - 600 ? 'cooldown' : null);
                if ($notice->status === 'pending') $binding->forceFill(['notice_last_alert_at' => time()])->save();
            }));
        });
    }

    public function resetDns(int $id): void
    {
        $this->safely(fn() => $this->locked('dns', $id, fn() => MachineDnsBinding::query()->whereKey($id)->update(['notice_episode' => null])));
    }

    public function cloud(int $id, bool $recovered = false): void
    {
        $this->safely(function () use ($id, $recovered) {
            $task = MachineCloudTask::query()->find($id);
            if (!$task) return;
            $this->locked('cloud', (int) $task->resource_id, function () use ($id, $recovered) {
                $task = MachineCloudTask::query()->find($id);
                $resource = $task ? MachineCloudResource::query()->find($task->resource_id) : null;
                if (!$resource) return;
                $phase = $recovered ? 'recovered' : match ($task->status) {
                    'failed', 'expired' => 'failed', 'uncertain', 'dispatching' => 'uncertain', 'completed' => 'completed', default => null,
                };
                if (!$phase || ($recovered && (!$task->dispatched_at || $resource->status !== 'running' || $resource->dns_hold))) return;
                $prior = $recovered ? Notice::query()->where('scope', 'cloud')->where('source_id', $id)
                    ->whereIn('phase', ['failed', 'uncertain'])->where('status', 'submitted')->exists() : true;
                $snapshot = ['machine_id' => $resource->machine_id, 'installation' => substr((string) $resource->installation_id, 0, 8),
                    'cloud_id' => $resource->external_id, 'action' => $task->action, 'result' => $task->status,
                    'reason' => MachineOperationNoticeText::reason((string) $task->reason), 'bound' => $resource->machine_id !== null];
                $this->record('cloud', $id, (int) $resource->id, null, $phase, $this->cloudContext($resource), $snapshot, $prior ? null : 'no_prior_notice');
            });
        });
    }

    public function replacement(int $id, bool $recovered = false): void
    {
        $this->safely(function () use ($id, $recovered) {
            if (!$this->scopeAvailable('provision')) return;
            $this->locked('provision', $id, function () use ($id, $recovered) {
                $row = MachineReplacementTask::query()->find($id);
                $task = $row ? MachineProvisionTask::query()->find($row->task_id) : null;
                if (!$row || !$task || ($recovered && !$row->source_recovered_at)) return;
                $phase = $recovered ? 'recovered' : $this->replacementPhase($row);
                $prior = !$recovered || Notice::query()->where('scope', 'provision')->where('source_id', $id)
                    ->where('status', 'submitted')->where('phase', '!=', 'recovered')->exists();
                $this->record('provision', $id, null, $recovered ? $row->recovery_event : $row->notice_event, $phase,
                    $this->replacementContext($row, $recovered), ['machine_id' => $row->machine_id, 'policy_id' => $row->policy_id,
                        'task_id' => $task->id, 'installation' => substr($recovered ? $row->source_installation_id : $task->installation_id, 0, 8),
                        'cloud_id' => $task->external_id, 'result' => $row->status,
                        'reason' => MachineOperationNoticeText::reason((string) $row->reason)], $prior ? null : 'no_prior_notice');
            });
        });
    }

    private function record(string $scope, int $source, ?int $resource, ?string $episode, string $phase, string $context, array $snapshot, ?string $suppress): Notice
    {
        $policy = DB::table(self::POLICY)->where('scope', $scope)->first();
        $status = !$policy->enabled ? 'disabled' : ($suppress ? 'suppressed' : ($this->channelReady() ? 'pending' : 'panel_only'));
        $notice = Notice::query()->firstOrCreate(['event_key' => hash('sha256', implode(':', [$scope, $source, $episode ?? '', $phase]))],
            ['scope' => $scope, 'source_id' => $source, 'resource_id' => $resource, 'episode' => $episode, 'phase' => $phase,
                'context_hash' => $context, 'policy_revision' => (int) $policy->revision, 'snapshot' => $snapshot,
                'status' => $status, 'delivery_reason' => $suppress, 'next_dispatch_at' => 0, 'expires_at' => time() + 3600]);
        if ($notice->status === 'pending') $this->dispatch($notice);
        return $notice;
    }

    private function dispatch(Notice $notice): void
    {
        if (!Notice::query()->whereKey($notice->id)->where('status', 'pending')->where('next_dispatch_at', '<=', time())
            ->update(['next_dispatch_at' => time() + 60])) return;
        try { SendMachineOperationNoticeJob::dispatch((int) $notice->id)->afterCommit(); }
        catch (\Throwable) { /* The outbox retries enqueueing only; delivery is claimed separately. */ }
    }

    public function deliver(int $id): void
    {
        if (!$this->available()) return;
        $candidate = Notice::query()->find($id);
        if (!$candidate) return;
        $this->locked($candidate->scope, $candidate->scope === 'cloud' ? (int) $candidate->resource_id : $candidate->source_id, function () use ($id, $candidate) {
            $notice = DB::transaction(function () use ($id, $candidate) {
                $policy = DB::table(self::POLICY)->where('scope', $candidate->scope)->lockForUpdate()->first();
                $row = Notice::query()->lockForUpdate()->find($id);
                if (!$row || $row->status !== 'pending') return null;
                $reason = !$policy?->enabled || (int) $policy->revision !== $row->policy_revision || !$this->adminActive((int) $policy->updated_by) ? 'policy_changed'
                    : ($row->expires_at <= time() ? 'expired' : (!$this->current($row) ? 'source_changed' : null));
                if ($reason || !$this->channelReady()) {
                    $row->fill(['status' => $reason ? 'suppressed' : 'panel_only', 'delivery_reason' => $reason])->save(); return null;
                }
                $row->fill(['status' => 'sending', 'claimed_at' => time()])->save();
                return $row;
            });
            if (!$notice) return;
            // A successful fan-out submission is not proof of recipient delivery.
            $status = 'submitted';
            try { app(TelegramService::class)->sendMessageWithActiveAdmins(MachineOperationNoticeText::message($notice)); }
            catch (\Throwable) { $status = 'unknown'; }
            Notice::query()->whereKey($id)->where('status', 'sending')->update(['status' => $status,
                'delivery_reason' => $status === 'unknown' ? 'transport_unknown' : null]);
        });
    }

    private function current(Notice $notice): bool
    {
        if ($notice->scope === 'provision') {
            if (!$this->scopeAvailable('provision')) return false;
            $row = MachineReplacementTask::query()->find($notice->source_id);
            $recovered = $notice->phase === 'recovered';
            return $row && hash_equals($notice->context_hash, $this->replacementContext($row, $recovered))
                && ($recovered || $notice->phase === $this->replacementPhase($row))
                && app(MachineReplacementService::class)->noticeCurrent($row, $notice->snapshot, $recovered);
        }
        if ($notice->scope === 'dns') {
            $row = MachineDnsBinding::query()->find($notice->source_id);
            return $row && hash_equals($notice->context_hash, $this->dnsContext($row)) && ($notice->phase === 'failed'
                ? $row->notice_episode === $notice->episode && in_array($row->status, self::DNS_FAILURES, true)
                : $row->status === 'synced' && !$row->notice_episode);
        }
        $task = MachineCloudTask::query()->find($notice->source_id);
        $row = $task ? MachineCloudResource::query()->find($task->resource_id) : null;
        if (!$row || !hash_equals($notice->context_hash, $this->cloudContext($row))) return false;
        return match ($notice->phase) {
            'uncertain' => in_array($task->status, ['uncertain', 'dispatching'], true),
            'failed' => in_array($task->status, ['failed', 'expired'], true) && !Notice::query()->where('scope', 'cloud')->where('source_id', $task->id)->where('phase', 'recovered')->exists(),
            'completed' => $task->status === 'completed',
            'recovered' => $row->status === 'running' && !$row->dns_hold,
            default => false,
        };
    }

    public function advance(): array
    {
        if (!$this->available()) return ['queued' => 0, 'unknown' => 0];
        try { app(MachineReplacementService::class)->refreshStatuses(); }
        catch (\Throwable) { Log::warning('[MachineReplacement] Status reconciliation unavailable'); }
        $unknown = Notice::query()->where('status', 'sending')->where('claimed_at', '<', time() - 300)
            ->update(['status' => 'unknown', 'delivery_reason' => 'transport_unknown']);
        Notice::query()->where('status', 'pending')->where('expires_at', '<=', time())->update(['status' => 'suppressed', 'delivery_reason' => 'expired']);
        $rows = Notice::query()->where('status', 'pending')->where('next_dispatch_at', '<=', time())->orderBy('id')->limit(20)->get();
        foreach ($rows as $row) $this->dispatch($row);
        $old = Notice::query()->where('created_at', '<', time() - 86400 * 30)->whereNotIn('status', ['pending', 'sending'])
            ->where(fn($query) => $query->whereNull('episode')->orWhereNotIn('episode', MachineDnsBinding::query()->whereNotNull('notice_episode')->select('notice_episode')))
            ->orderBy('id')->limit(1000)->pluck('id');
        if ($old->isNotEmpty()) Notice::query()->whereIn('id', $old)->delete();
        return ['queued' => $rows->count(), 'unknown' => $unknown];
    }

    private function dnsSnapshot(MachineDnsBinding $binding): array
    {
        return ['machine_id' => $binding->machine_id, 'installation' => substr($binding->installation_id, 0, 8),
            'hostname' => $binding->hostname, 'type' => $binding->type, 'reason' => $binding->status, 'ip' => $binding->last_ip];
    }
    private function dnsContext(MachineDnsBinding $row): string
    {
        return hash('sha256', json_encode($row->only(['id', 'revision', 'credential_id', 'machine_id', 'installation_id', 'machine_authority', 'record_scope'])));
    }
    private function cloudContext(MachineCloudResource $row): string
    {
        return hash('sha256', json_encode($row->only(['id', 'external_id', 'provider_created_at', 'machine_id', 'installation_id', 'machine_authority'])));
    }
    private function replacementContext(MachineReplacementTask $row, bool $recovered): string
    {
        return hash('sha256', json_encode([$row->only(['id', 'policy_id', 'policy_revision', 'account_id', 'machine_id', 'task_id']),
            $recovered ? $row->recovery_event : $row->notice_event]));
    }
    private function replacementPhase(MachineReplacementTask $row): string
    {
        if ($row->reason === 'queue_unavailable') return 'failed';
        return match ($row->status) { 'failed' => 'failed', 'needs_review', 'outcome_unknown' => 'uncertain',
            'completed' => 'completed', default => 'progress' };
    }
    private function scopeAvailable(string $scope): bool
    {
        return $scope !== 'provision' || (Schema::hasTable('v2_machine_replacement_task')
            && DB::table(self::POLICY)->where('scope', $scope)->exists());
    }
    protected function channelReady(): bool
    {
        return (bool) admin_setting('telegram_bot_enable', false) && trim((string) admin_setting('telegram_bot_token', '')) !== ''
            && User::query()->where('is_admin', true)->where('banned', false)->whereNotNull('telegram_id')->exists();
    }
    private function adminActive(int $id): bool
    {
        $user = User::query()->find($id);
        return $user && $user->is_admin && !$user->banned;
    }
    private function scope(string $scope): void
    {
        if (!in_array($scope, ['cloud', 'dns', 'provision'], true)) throw new MachineOperationNoticeException('invalid_scope');
    }
    private function locked(string $scope, int $id, callable $work): mixed
    {
        return Cache::lock('machine-operation-notice:' . $scope . ':' . $id, 60)->block(2, $work);
    }
    private function safely(callable $work): void
    {
        try { if ($this->available()) $work(); }
        catch (\Throwable) { try { Log::warning('[MachineOperations] Notification capture unavailable'); } catch (\Throwable) {} }
    }
}
