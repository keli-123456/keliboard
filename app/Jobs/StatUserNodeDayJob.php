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

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    public function handle(): void
    {
        $recordAt = $this->recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));

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
            $this->upsertRowsForMySqlLike($rows);
        } catch (\Throwable $e) {
            Log::error('StatUserNodeDayJob failed for server ' . ($this->server['id'] ?? 'unknown') . ': ' . $e->getMessage());
            throw $e;
        }
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
}
