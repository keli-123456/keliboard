<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TrafficBatchApplyJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TrafficBatchService
{
    public function accept(Server $node, mixed $reportId, mixed $traffic): object
    {
        $reportId = TrafficBatchPayload::reportId($reportId);
        $traffic = TrafficBatchPayload::normalize($traffic);
        $hash = TrafficBatchPayload::contentHash($traffic);
        $this->assertTransactionalStorage();
        return DB::transaction(function () use ($node, $reportId, $traffic, $hash): object {
            $this->sqliteWriteLock('v2_server', (int) $node->id);
            // Serialize admission per authenticated node, including first insertion races.
            $current = Server::query()->whereKey($node->id)->lockForUpdate()->first();
            if (!$current || $current->type !== $node->type) {
                throw new ConflictHttpException('Authenticated node identity changed.');
            }
            $existing = DB::table('v2_traffic_batch')->where('node_id', $current->id)
                ->where('report_id', $reportId)->first();
            if ($existing) {
                if (!hash_equals($existing->content_hash, $hash) || $existing->node_type !== $current->type) {
                    throw new ConflictHttpException('Traffic report ID was reused with a different payload.');
                }
                return $existing;
            }
            $server = $current->toArray();
            $server['rate'] = $current->getCurrentRate();
            $protocol = (string) $current->type;
            [$server, $protocol, $filtered] = HookManager::filter('traffic.process.before', [$server, $protocol, $traffic]);
            [$server, $protocol, $filtered] = HookManager::filter('traffic.before_process', [$server, $protocol, $filtered]);
            if ((int) ($server['id'] ?? 0) !== (int) $current->id || $protocol !== $current->type) {
                throw new RuntimeException('Traffic filter cannot change authenticated batch ownership.');
            }
            $filtered = TrafficBatchPayload::normalize($filtered);
            $cents = TrafficBatchPayload::rate($server['rate'] ?? 1);
            foreach ($filtered as [$upload, $download]) {
                TrafficBatchPayload::scale($upload, $cents);
                TrafficBatchPayload::scale($download, $cents);
            }
            $now = time();
            $payload = json_encode([
                'version' => 1, 'node_id' => (int) $current->id, 'node_type' => $protocol,
                'node_name' => (string) $current->name, 'rate_cents' => $cents,
                'record_at' => strtotime(date('Y-m-d', $now)), 'traffic' => $filtered,
            ], JSON_THROW_ON_ERROR);
            if (strlen($payload) > TrafficBatchPayload::MAX_BODY_BYTES) {
                throw new \InvalidArgumentException('Prepared traffic exceeds batch size limit.');
            }
            $id = DB::table('v2_traffic_batch')->insertGetId([
                'node_id' => (int) $current->id, 'node_type' => $protocol,
                'report_id' => $reportId, 'content_hash' => $hash,
                'prepared_hash' => hash('sha256', $payload),
                'payload' => $payload, 'created_at' => $now,
            ]);
            return DB::table('v2_traffic_batch')->where('id', $id)->first();
        }, 3);
    }

    public function dispatch(object $receipt): bool
    {
        if ($receipt->sync_finished_at !== null) {
            return false;
        }
        $now = time();
        // A short database lease bounds duplicate kicks and lets newer rows pass poison rows.
        if (!DB::table('v2_traffic_batch')->where('id', $receipt->id)->whereNull('sync_finished_at')
            ->where('retry_at', '<=', $now)->update(['retry_at' => $now + 120])) {
            return false;
        }
        try {
            TrafficBatchApplyJob::dispatch((int) $receipt->id);
            return true;
        } catch (\Throwable $error) {
            // The database is the durable queue. Scheduled recovery re-enqueues lost kicks.
            Log::warning('Traffic batch dispatch pending recovery', [
                'receipt_id' => $receipt->id, 'error_class' => get_class($error),
            ]);
            return false;
        }
    }

    public function recover(int $limit): int
    {
        $rows = DB::table('v2_traffic_batch')->useWritePdo()->whereNull('sync_finished_at')
            ->where('retry_at', '<=', time())->orderBy('retry_at')->orderBy('id')
            ->limit(max(1, min($limit, 1000)))->get();
        $dispatched = 0;
        foreach ($rows as $row) {
            $dispatched += (int) $this->dispatch($row);
        }
        return $dispatched;
    }

    public function process(int $id): void
    {
        $this->assertTransactionalStorage();
        DB::transaction(function () use ($id): void {
            $this->sqliteWriteLock('v2_traffic_batch', $id);
            $row = DB::table('v2_traffic_batch')->where('id', $id)->lockForUpdate()->first();
            if (!$row) {
                throw new RuntimeException('Traffic batch receipt is missing.');
            }
            if ($row->processed_at !== null) {
                return;
            }
            $payload = $this->payload($row);
            app(TrafficBatchAccounting::class)->apply($payload);
            DB::table('v2_traffic_batch')->where('id', $id)->update(['processed_at' => time()]);
        }, 3);
        // Quota invalidation can retry independently; it must never repeat accounting.
        $row = DB::table('v2_traffic_batch')->useWritePdo()->where('id', $id)->first();
        if ($row->sync_finished_at !== null) {
            return;
        }
        $payload = $this->payload($row);
        if (DB::getSchemaBuilder()->hasTable('user_sync_states')) {
            foreach (array_keys($payload['traffic']) as $userId) {
                DB::transaction(function () use ($userId): void {
                    $this->sqliteWriteLock('v2_user', (int) $userId);
                    // Snapshot under the user lock on the primary, never a stale read replica.
                    $user = User::query()->lockForUpdate()->find($userId);
                    if ($user) {
                        app(UserSyncService::class)->syncUser($user, 'traffic_exceeded');
                    }
                }, 3);
            }
        }
        // Retry only completion bookkeeping, never the already committed accounting.
        DB::transaction(function () use ($id): void {
            DB::table('v2_traffic_batch')->where('id', $id)->whereNotNull('processed_at')
                ->whereNull('sync_finished_at')->update(['sync_finished_at' => time(), 'payload' => null]);
        }, 3);
    }

    private function payload(object $row): array
    {
        if (!is_string($row->payload) || !hash_equals($row->prepared_hash, hash('sha256', $row->payload))) {
            throw new RuntimeException('Durable traffic batch checksum mismatch.');
        }
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['version'] ?? null) !== 1 || ($payload['node_id'] ?? null) !== (int) $row->node_id
            || ($payload['node_type'] ?? null) !== $row->node_type
            || !is_int($payload['record_at'] ?? null) || $payload['record_at'] <= 0
            || !is_int($payload['rate_cents'] ?? null) || $payload['rate_cents'] < 0
            || $payload['rate_cents'] > 99999999) {
            throw new RuntimeException('Invalid durable traffic batch payload.');
        }
        return $payload;
    }

    private function assertTransactionalStorage(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported durable traffic database driver.');
        }
        if ($driver === 'mysql') {
            $tables = ['v2_server', 'v2_traffic_batch', 'v2_user', 'v2_stat_user', 'v2_stat_user_node_day', 'v2_stat_server'];
            $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)->pluck('ENGINE', 'TABLE_NAME');
            foreach ($tables as $table) {
                if (strtolower((string) $engines->get($table)) !== 'innodb') {
                    throw new RuntimeException('Durable traffic accounting requires all tables to use InnoDB.');
                }
            }
        }
    }

    private function sqliteWriteLock(string $table, int $id): void
    {
        // SQLite ignores FOR UPDATE. Acquire write intent before a deferred transaction reads.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        }
    }
}
