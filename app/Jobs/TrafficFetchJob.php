<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\UserSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $increments = $this->normalizeTrafficData($this->data);
        if (empty($increments)) {
            return;
        }

        $this->applyTrafficIncrements($increments);

        // Emit user_sync events when a user crosses the traffic quota boundary.
        $uids = array_keys($increments);
        if (empty($uids) || !DB::getSchemaBuilder()->hasTable('user_sync_states')) {
            return;
        }

        $exceeded = DB::table('v2_user as u')
            ->join('user_sync_states as s', 's.user_id', '=', 'u.id')
            ->whereIn('u.id', $uids)
            ->where('s.available', 1)
            ->whereNotNull('u.transfer_enable')
            ->whereRaw('(COALESCE(u.u, 0) + COALESCE(u.d, 0)) >= u.transfer_enable')
            ->pluck('u.id');

        if ($exceeded->isEmpty()) {
            return;
        }

        $sync = app(UserSyncService::class);
        foreach ($exceeded as $userId) {
            $sync->syncUserById((int) $userId, 'traffic_exceeded');
        }
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int, array{u:int|float, d:int|float}>
     */
    private function normalizeTrafficData(array $data): array
    {
        $rate = (float) ($this->server['rate'] ?? 1);
        $normalized = [];

        foreach ($data as $uid => $traffic) {
            $userId = (int) $uid;
            if ($userId <= 0 || !is_array($traffic) || count($traffic) < 2) {
                continue;
            }

            $upload = is_numeric($traffic[0] ?? null) ? (float) $traffic[0] : 0.0;
            $download = is_numeric($traffic[1] ?? null) ? (float) $traffic[1] : 0.0;

            $normalized[$userId] = [
                'u' => $upload * $rate,
                'd' => $download * $rate,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{u:int|float, d:int|float}> $increments
     */
    private function applyTrafficIncrements(array $increments): void
    {
        $driver = (string) config('database.default');
        if ($driver === 'sqlite') {
            $this->applyTrafficIncrementsLegacy($increments);
            return;
        }

        try {
            [$sql, $bindings] = $this->buildBatchUpdateStatement($increments, time());
            DB::update($sql, $bindings);
        } catch (\Throwable $e) {
            Log::warning('TrafficFetchJob batch update failed, fallback to per-user updates', [
                'error' => $e->getMessage(),
                'users' => count($increments),
            ]);
            $this->applyTrafficIncrementsLegacy($increments);
        }
    }

    /**
     * @param array<int, array{u:int|float, d:int|float}> $increments
     */
    private function applyTrafficIncrementsLegacy(array $increments): void
    {
        $now = time();
        foreach ($increments as $userId => $traffic) {
            User::where('id', $userId)
                ->incrementEach(
                    [
                        'u' => $traffic['u'],
                        'd' => $traffic['d'],
                    ],
                    ['t' => $now]
                );
        }
    }

    /**
     * @param array<int, array{u:int|float, d:int|float}> $increments
     * @return array{0:string,1:array<int, int|float>}
     */
    private function buildBatchUpdateStatement(array $increments, int $timestamp): array
    {
        $ids = array_keys($increments);
        $uCaseParts = [];
        $dCaseParts = [];
        $bindings = [];

        foreach ($increments as $id => $traffic) {
            $uCaseParts[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $traffic['u'];
        }

        foreach ($increments as $id => $traffic) {
            $dCaseParts[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $traffic['d'];
        }

        $bindings[] = $timestamp;
        foreach ($ids as $id) {
            $bindings[] = $id;
        }

        $inPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = sprintf(
            'UPDATE %s SET u = u + CASE id %s ELSE 0 END, d = d + CASE id %s ELSE 0 END, t = ? WHERE id IN (%s)',
            (new User())->getTable(),
            implode(' ', $uCaseParts),
            implode(' ', $dCaseParts),
            $inPlaceholders
        );

        return [$sql, $bindings];
    }
}
