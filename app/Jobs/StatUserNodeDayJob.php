<?php

namespace App\Jobs;

use App\Models\StatUserNodeDay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatUserNodeDayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;
    protected ?int $recordAt = null;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd', ?int $recordAt = null)
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->recordAt = $this->normalizeRecordAt($recordAt);
    }

    public function handle(): void
    {
        $recordAt = $this->recordAt ?? $this->currentRecordAt();

        $rows = [];
        foreach ($this->data as $uid => $traffic) {
            $upload = (float) ($traffic[0] ?? 0);
            $download = (float) ($traffic[1] ?? 0);
            if ($upload <= 0 && $download <= 0) {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $uid,
                'server_id' => (int) ($this->server['id'] ?? 0),
                'server_type' => (string) ($this->protocol ?: ($this->server['type'] ?? '')),
                'server_name' => (string) ($this->server['name'] ?? ''),
                'server_rate' => (float) ($this->server['rate'] ?? 1),
                'u' => $upload * (float) ($this->server['rate'] ?? 1),
                'd' => $download * (float) ($this->server['rate'] ?? 1),
                'record_type' => $this->recordType,
                'record_at' => $recordAt,
                'created_at' => time(),
                'updated_at' => time(),
            ];
        }

        if (!$rows) {
            return;
        }

        try {
            $driver = config('database.default');
            if ($driver === 'sqlite') {
                $this->upsertRowsForSqlite($rows);
                return;
            }
            if ($driver === 'pgsql') {
                $this->upsertRowsForPostgres($rows);
                return;
            }
            $this->upsertRowsForMySqlWithRetry($rows);
        } catch (\Throwable $e) {
            Log::error('StatUserNodeDayJob failed', [
                'server_id' => $this->server['id'] ?? null,
                'error' => $this->summarizeDatabaseError($e),
            ]);
            throw $e;
        }
    }

    protected function upsertRowsForMySqlWithRetry(array $rows): void
    {
        usort($rows, fn (array $left, array $right): int => [
            $left['user_id'],
            $left['server_id'],
            $left['server_rate'],
            $left['record_at'],
            $left['record_type'],
        ] <=> [
            $right['user_id'],
            $right['server_id'],
            $right['server_rate'],
            $right['record_at'],
            $right['record_type'],
        ]);

        foreach (array_chunk($rows, 100) as $batch) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                try {
                    $this->upsertRowsForMySqlLike($batch);
                    break;
                } catch (\Throwable $e) {
                    if (!$this->isMySqlDeadlock($e) || $attempt === 5) {
                        throw $e;
                    }

                    Log::warning('StatUserNodeDayJob retrying MySQL deadlock', [
                        'server_id' => $this->server['id'] ?? null,
                        'attempt' => $attempt,
                        'error' => $this->summarizeDatabaseError($e),
                    ]);
                    usleep(($attempt * 100000) + random_int(0, 150000));
                }
            }
        }
    }

    protected function summarizeDatabaseError(\Throwable $e): string
    {
        $message = preg_split('/\s+\(Connection:/i', $e->getMessage(), 2)[0] ?? $e->getMessage();

        return mb_substr(trim($message), 0, 500);
    }

    private function isMySqlDeadlock(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'sqlstate[40001]')
            || str_contains($message, ' 1213 ');
    }

    protected function upsertRowsForSqlite(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $existing = StatUserNodeDay::where([
                    'user_id' => $row['user_id'],
                    'server_id' => $row['server_id'],
                    'server_rate' => $row['server_rate'],
                    'record_at' => $row['record_at'],
                    'record_type' => $row['record_type'],
                ])->first();

                if ($existing) {
                    $existing->update([
                        'server_type' => $row['server_type'],
                        'server_name' => $row['server_name'],
                        'u' => $existing->u + $row['u'],
                        'd' => $existing->d + $row['d'],
                        'updated_at' => $row['updated_at'],
                    ]);
                    continue;
                }

                StatUserNodeDay::create($row);
            }
        }, 3);
    }

    protected function upsertRowsForMySqlLike(array $rows): void
    {
        $table = (new StatUserNodeDay())->getTable();
        $columns = ['user_id', 'server_id', 'server_type', 'server_name', 'server_rate', 'u', 'd', 'record_type', 'record_at', 'created_at', 'updated_at'];
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ' . implode(', ', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                server_type = VALUES(server_type),
                server_name = VALUES(server_name),
                u = {$table}.u + VALUES(u),
                d = {$table}.d + VALUES(d),
                updated_at = VALUES(updated_at)";

        DB::statement($sql, $bindings);
    }

    protected function upsertRowsForPostgres(array $rows): void
    {
        $table = (new StatUserNodeDay())->getTable();
        $columns = ['user_id', 'server_id', 'server_type', 'server_name', 'server_rate', 'u', 'd', 'record_type', 'record_at', 'created_at', 'updated_at'];
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ' . implode(', ', $placeholders) . "
            ON CONFLICT (user_id, server_id, server_rate, record_at, record_type)
            DO UPDATE SET
                server_type = EXCLUDED.server_type,
                server_name = EXCLUDED.server_name,
                u = {$table}.u + EXCLUDED.u,
                d = {$table}.d + EXCLUDED.d,
                updated_at = EXCLUDED.updated_at";

        DB::statement($sql, $bindings);
    }

    private function currentRecordAt(): int
    {
        return $this->normalizeRecordAt(time()) ?? time();
    }

    private function normalizeRecordAt(?int $timestamp): ?int
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        $date = $this->recordType === 'm'
            ? date('Y-m-01', $timestamp)
            : date('Y-m-d', $timestamp);
        $recordAt = strtotime($date);

        return $recordAt === false ? null : $recordAt;
    }
}
