<?php


namespace App\Jobs;

use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Create a new job instance.
     */
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

        $driver = (string) config('database.default');
        if ($driver === 'sqlite') {
            $this->processUserStatsLegacy($recordAt);
            return;
        }

        try {
            $rows = $this->buildRows($recordAt);
            if (empty($rows)) {
                return;
            }

            if ($driver === 'pgsql') {
                [$sql, $bindings] = $this->buildPostgresBatchUpsertStatement($rows);
                DB::statement($sql, $bindings);
                return;
            }

            [$sql, $bindings] = $this->buildMySqlBatchUpsertStatement($rows);
            DB::statement($sql, $bindings);
        } catch (\Throwable $e) {
            Log::warning('StatUserJob batch upsert failed, fallback to per-user upsert', [
                'error' => $e->getMessage(),
                'users' => count($this->data),
            ]);
            $this->processUserStatsLegacy($recordAt);
        }
    }

    private function processUserStatsLegacy(int $recordAt): void
    {
        foreach ($this->data as $uid => $v) {
            try {
                $this->processUserStat((int) $uid, (array) $v, $recordAt);
            } catch (\Exception $e) {
                Log::error('StatUserJob failed for user ' . $uid . ': ' . $e->getMessage());
                throw $e;
            }
        }
    }

    protected function processUserStat(int $uid, array $v, int $recordAt): void
    {
        $driver = config('database.default');
        if ($driver === 'sqlite') {
            $this->processUserStatForSqlite($uid, $v, $recordAt);
        } elseif ($driver === 'pgsql') {
            $this->processUserStatForPostgres($uid, $v, $recordAt);
        } else {
            $this->processUserStatForOtherDatabases($uid, $v, $recordAt);
        }
    }

    protected function processUserStatForSqlite(int $uid, array $v, int $recordAt): void
    {
        DB::transaction(function () use ($uid, $v, $recordAt) {
            $existingRecord = StatUser::where([
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
            ])->first();

            if ($existingRecord) {
                $existingRecord->update([
                    'u' => $existingRecord->u + ($v[0] * $this->server['rate']),
                    'd' => $existingRecord->d + ($v[1] * $this->server['rate']),
                    'updated_at' => time(),
                ]);
            } else {
                StatUser::create([
                    'user_id' => $uid,
                    'server_rate' => $this->server['rate'],
                    'record_at' => $recordAt,
                    'record_type' => $this->recordType,
                    'u' => ($v[0] * $this->server['rate']),
                    'd' => ($v[1] * $this->server['rate']),
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
        }, 3);
    }

    protected function processUserStatForOtherDatabases(int $uid, array $v, int $recordAt): void
    {
        StatUser::upsert(
            [
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => ($v[0] * $this->server['rate']),
                'd' => ($v[1] * $this->server['rate']),
                'created_at' => time(),
                'updated_at' => time(),
            ],
            ['user_id', 'server_rate', 'record_at', 'record_type'],
            [
                'u' => DB::raw("u + VALUES(u)"),
                'd' => DB::raw("d + VALUES(d)"),
                'updated_at' => time(),
            ]
        );
    }

    /**
     * PostgreSQL upsert with arithmetic increments using ON CONFLICT ... DO UPDATE
     */
    protected function processUserStatForPostgres(int $uid, array $v, int $recordAt): void
    {
        $table = (new StatUser())->getTable();
        $now = time();
        $u = ($v[0] * $this->server['rate']);
        $d = ($v[1] * $this->server['rate']);

        $sql = "INSERT INTO {$table} (user_id, server_rate, record_at, record_type, u, d, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (user_id, server_rate, record_at)
                DO UPDATE SET
                    u = {$table}.u + EXCLUDED.u,
                    d = {$table}.d + EXCLUDED.d,
                    updated_at = EXCLUDED.updated_at";

        DB::statement($sql, [
            $uid,
            $this->server['rate'],
            $recordAt,
            $this->recordType,
            $u,
            $d,
            $now,
            $now,
        ]);
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function buildRows(int $recordAt): array
    {
        $rows = [];
        $now = time();
        $rate = (float) ($this->server['rate'] ?? 1);

        foreach ($this->data as $uid => $traffic) {
            $userId = (int) $uid;
            if ($userId <= 0 || !is_array($traffic) || count($traffic) < 2) {
                continue;
            }

            $upload = is_numeric($traffic[0] ?? null) ? (float) $traffic[0] : 0.0;
            $download = is_numeric($traffic[1] ?? null) ? (float) $traffic[1] : 0.0;

            $rows[] = [
                'user_id' => $userId,
                'server_rate' => $rate,
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => $upload * $rate,
                'd' => $download * $rate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, int|float|string>> $rows
     * @return array{0:string,1:array<int, int|float|string>}
     */
    private function buildMySqlBatchUpsertStatement(array $rows): array
    {
        $table = (new StatUser())->getTable();
        $columns = ['user_id', 'server_rate', 'record_at', 'record_type', 'u', 'd', 'created_at', 'updated_at'];
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
                u = {$table}.u + VALUES(u),
                d = {$table}.d + VALUES(d),
                updated_at = VALUES(updated_at)";

        return [$sql, $bindings];
    }

    /**
     * @param array<int, array<string, int|float|string>> $rows
     * @return array{0:string,1:array<int, int|float|string>}
     */
    private function buildPostgresBatchUpsertStatement(array $rows): array
    {
        $table = (new StatUser())->getTable();
        $columns = ['user_id', 'server_rate', 'record_at', 'record_type', 'u', 'd', 'created_at', 'updated_at'];
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ' . implode(', ', $placeholders) . "
            ON CONFLICT (user_id, server_rate, record_at)
            DO UPDATE SET
                u = {$table}.u + EXCLUDED.u,
                d = {$table}.d + EXCLUDED.d,
                updated_at = EXCLUDED.updated_at";

        return [$sql, $bindings];
    }
}
