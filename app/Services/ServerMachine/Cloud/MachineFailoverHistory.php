<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverReviewAction as Action;
use Illuminate\Support\Facades\DB;

class MachineFailoverHistory
{
    public function page(int $service, int $revision, int $admin, string $filter = 'open', ?string $cursor = null): array
    {
        app(MachineFailoverServiceRegistry::class)->actor($admin);
        if (!app(MachineFailoverReview::class)->available()) throw new CloudException('review_migration_required');
        if (!in_array($filter, ['open', 'all', 'closed'], true)) throw new CloudException('invalid_history_filter');
        $row = \App\Models\MachineFailoverService::query()->findOrFail($service);
        if ($row->revision !== $revision) throw new CloudException('config_changed');
        $version = (int) Action::query()->max('id');
        $open = $this->openMembers();
        $base = Execution::query()->where('service_id', $service)->select('v2_machine_failover_execution.*')
            ->selectRaw('CASE WHEN EXISTS (' . $open->toSql() . ') THEN 0 ELSE 1 END AS review_rank', $open->getBindings());
        if ($filter === 'open') $base->whereExists($this->openMembers());
        if ($filter === 'closed') $base->where('status', '!=', 'running')->whereNotExists($this->openMembers());
        $query = DB::query()->fromSub($base->toBase(), 'history'); $total = (clone $query)->count();
        if ($cursor !== null) {
            try { $token = json_decode(app('encrypter')->decryptString($cursor), true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new CloudException('history_cursor_invalid'); }
            if (($token['scope'] ?? null) !== [$service, $revision, $filter] || ($token['version'] ?? null) !== $version
                || ($token['until'] ?? 0) <= time() || !is_array($token['after'] ?? null) || count($token['after']) !== 3) throw new CloudException('history_changed');
            [$rank, $at, $id] = $token['after'];
            if (!is_int($rank) || !in_array($rank, [0, 1], true) || !is_int($at) || !is_string($id)) throw new CloudException('history_cursor_invalid');
            $query->where(fn ($q) => $q->where('review_rank', '>', $rank)->orWhere(fn ($q) => $q->where('review_rank', $rank)
                ->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)))));
        }
        $rows = $query->orderBy('review_rank')->orderByDesc('created_at')->orderByDesc('id')->limit(21)->get();
        $next = null;
        if ($rows->count() > 20) {
            $last = $rows[19];
            $next = app('encrypter')->encryptString(json_encode(['scope' => [$service, $revision, $filter], 'version' => $version,
                'until' => time() + 900, 'after' => [(int) $last->review_rank, (int) $last->created_at, $last->id]], JSON_THROW_ON_ERROR));
        }
        $items = $rows->take(20)->map(fn ($r) => app(MachineFailoverBudget::class)->runSummary(Execution::query()->findOrFail($r->id), true))->all();
        if ((int) Action::query()->max('id') !== $version) throw new CloudException('history_changed');
        return ['service_id' => $service, 'service_revision' => $revision, 'filter' => $filter, 'items' => $items, 'total' => $total,
            'next_cursor' => $next, 'cloud_writes' => 0, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false];
    }
    private function openMembers()
    {
        return DB::table('v2_machine_failover_execution_member AS em')->selectRaw('1')
            ->whereColumn('em.execution_id', 'v2_machine_failover_execution.id')->whereIn('em.phase', ['needs_review', 'retained', 'cancelled'])
            ->where(function ($q) {
                $q->whereExists(function ($a) {
                    $a->selectRaw('1')->from('v2_machine_failover_attempt AS a')->whereColumn('a.execution_member_id', 'em.id')
                        ->whereNotExists(fn ($c) => $c->selectRaw('1')->from('v2_machine_failover_review_action AS c')->whereColumn('c.attempt_id', 'a.id')->where('c.operation', 'close'));
                })->orWhere(function ($q) {
                    $q->whereIn('em.phase', ['needs_review', 'retained'])
                        ->whereNotExists(fn ($a) => $a->selectRaw('1')->from('v2_machine_failover_attempt AS a')->whereColumn('a.execution_member_id', 'em.id'))
                        ->whereNotExists(fn ($c) => $c->selectRaw('1')->from('v2_machine_failover_review_action AS c')->whereColumn('c.member_id', 'em.id')->whereNull('c.attempt_id')->where('c.operation', 'close'));
                });
            });
    }
    public function archives(array $attemptIds, ?bool $ready = null): array
    {
        if (!($ready ?? app(MachineFailoverReview::class)->available()) || !$attemptIds) return [];
        $rows = [];
        foreach (array_chunk($attemptIds, 300) as $ids) {
            $latest = Action::query()->selectRaw('MAX(id)')->whereIn('attempt_id', $ids)->whereIn('operation', ['archive', 'restore'])->groupBy('attempt_id');
            foreach (Action::query()->whereIn('id', $latest)->get() as $row) if ($row->operation === 'archive') $rows[$row->attempt_id] = $row;
        }
        return $rows;
    }
}
