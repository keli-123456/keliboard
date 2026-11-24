<?php

namespace App\Services\ServerMachine\Cloud;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MachineFailoverServiceActivity
{
    private const NATIVE_TERMINAL = ['completed', 'cancelled', 'retained', 'needs_review'];
    private const STATUSES = ['running', 'completed', 'cancelled', 'retained', 'needs_review', 'stopped', 'failed', 'partial', 'blocked'];

    public function overview(array $ids): array
    {
        if (!$ids) return [];
        $sources = ['simulation' => ['run', 'run_member', 'run_id', MachineFailoverStateMachine::TERMINAL]];
        if (Schema::hasTable('v2_machine_failover_execution') && Schema::hasTable('v2_machine_failover_execution_member'))
            $sources['native'] = ['execution', 'execution_member', 'execution_id', self::NATIVE_TERMINAL];

        $chosen = [];
        foreach ($sources as $mode => [$table]) {
            foreach ($this->latest($table, $ids, $mode) as $run) {
                $current = $chosen[$run->service_id] ?? null;
                $rank = fn ($row) => [$row->status === 'running', $row->status === 'running' && $row->mode === 'native',
                    (int) $row->created_at, $row->mode === 'native', (int) $row->updated_at, $row->id];
                if ($current === null || $rank($run) > $rank($current)) $chosen[$run->service_id] = $run;
            }
        }

        $summaries = [];
        foreach ($sources as $mode => [, $memberTable, $key, $terminals]) {
            $runs = array_filter($chosen, fn ($row) => $row->mode === $mode);
            if (!$runs) continue;
            $runIds = array_column($runs, 'id');
            $phases = DB::table('v2_machine_failover_' . $memberTable)->whereIn($key, $runIds)->select($key, 'phase')
                ->selectRaw('count(*) as amount, max(updated_at) as last_change')->groupBy($key, 'phase')->get()->groupBy($key);
            $stopping = $mode === 'native' && Schema::hasTable('v2_machine_failover_runtime')
                ? DB::table('v2_machine_failover_runtime')->whereIn('execution_id', $runIds)->whereNotNull('stop_requested_at')
                    ->pluck('updated_at', 'execution_id')->all() : [];
            foreach ($runs as $service => $run) {
                $rows = $phases->get($run->id, collect());
                [$closed, $open] = $rows->partition(fn ($r) => in_array($r->phase, $terminals, true));
                $phase = $open->count() === 1 ? $open->first()->phase : null;
                $stopAt = (int) ($stopping[$run->id] ?? 0);
                $summaries[$service] = ['mode' => $mode, 'status' => $run->status === 'running' && $stopAt > 0 ? 'stopping' : $run->status,
                    'service_revision' => (int) $run->service_revision,
                    'phase' => $run->status === 'running' && !$stopAt && is_string($phase) && preg_match('/^[a-z_]{1,32}$/D', $phase) ? $phase : null,
                    'done' => (int) $closed->sum('amount'), 'total' => (int) $rows->sum('amount'),
                    'updated_at' => max((int) $run->created_at, (int) $run->updated_at, (int) $rows->max('last_change'), $stopAt)];
            }
        }
        return $summaries;
    }

    private function latest(string $table, array $ids, string $mode): iterable
    {
        $table = 'v2_machine_failover_' . $table;
        $runs = fn () => DB::table($table)->whereIn('status', self::STATUSES)
            ->when($mode === 'simulation', fn ($q) => $q->where('mode', 'simulation'));
        // At most one indexed per-service choice per journal, then aggregate only the selected runs.
        $candidate = $runs()->select('id')->whereColumn('service_id', 'svc.id')
            ->orderByRaw('case when status = ? then 0 else 1 end', ['running'])->latest('created_at')->latest('id')->limit(1);
        $picked = DB::table('v2_machine_failover_service as svc')->whereIn('svc.id', $ids)->selectSub($candidate, 'run_id');
        return $runs()->whereIn('id', $picked)->get(['id', 'service_id', 'service_revision', 'status', 'created_at', 'updated_at'])
            ->map(function ($run) use ($mode) { $run->mode = $mode; return $run; });
    }
}
