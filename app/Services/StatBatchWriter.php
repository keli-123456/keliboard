<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

final class StatBatchWriter
{
    public const RECEIPTS = 'v2_stat_job_receipt';

    private const BATCH_SIZE = 100;
    private const ATTEMPTS = 3;

    private const KEYS = [
        'v2_stat_user' => ['user_id', 'server_rate', 'record_at', 'record_type'],
        'v2_stat_user_node_day' => ['user_id', 'server_id', 'server_rate', 'record_at', 'record_type'],
        'v2_stat_server' => ['server_id', 'server_type', 'record_at', 'record_type'],
    ];

    public function write(string $table, string $deliveryId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $key = self::KEYS[$table] ?? null;
        if ($key === null || !Str::isUuid($deliveryId)) {
            throw new InvalidArgumentException('Invalid statistics delivery identity.');
        }

        // Take overlapping row locks in the same order in every delivery.
        $compare = static function (array $left, array $right) use ($key): int {
            foreach ($key as $column) {
                if (($order = $left[$column] <=> $right[$column]) !== 0) {
                    return $order;
                }
            }

            return 0;
        };
        usort($rows, $compare);

        // PostgreSQL cannot upsert the same destination twice in one statement.
        $ordered = [];
        foreach ($rows as $row) {
            $last = array_key_last($ordered);
            if ($last !== null && $compare($ordered[$last], $row) === 0) {
                $ordered[$last]['u'] += $row['u'];
                $ordered[$last]['d'] += $row['d'];
            } else {
                $ordered[] = $row;
            }
        }

        $content = array_map(static function (array $row): array {
            unset($row['created_at'], $row['updated_at']);
            ksort($row);

            return $row;
        }, $ordered);
        $hash = hash('sha256', serialize([$table, $content]));
        $deliveryId = strtolower($deliveryId);

        $db = DB::connection();
        $updates = $this->additiveUpdates($db, $table);
        $batches = array_chunk($ordered, self::BATCH_SIZE);
        $detector = new ConcurrencyErrorDetector();
        $outerTransaction = $db->transactionLevel() !== 0;

        for ($attempt = 1; ; $attempt++) {
            try {
                $db->transaction(function () use ($db, $deliveryId, $hash, $table, $key, $batches, $updates): void {
                    $receipt = ['delivery_id' => $deliveryId, 'content_hash' => $hash, 'applied_at' => time()];
                    // The receipt and every increment commit together, even when a commit acknowledgement is lost.
                    if ($db->table(self::RECEIPTS)->insertOrIgnore($receipt) !== 1) {
                        $existing = $db->table(self::RECEIPTS)->where('delivery_id', $deliveryId)
                            ->sharedLock()->value('content_hash');
                        if (!is_string($existing) || !hash_equals($existing, $hash)) {
                            throw new RuntimeException('Statistics delivery receipt is missing or conflicts with the batch.');
                        }

                        return;
                    }

                    foreach ($batches as $batch) {
                        $db->table($table)->upsert($batch, $key, $updates);
                    }
                });

                return;
            } catch (Throwable $error) {
                if ($outerTransaction || $attempt >= self::ATTEMPTS || !$detector->causedByConcurrencyError($error)) {
                    if ($error instanceof PDOException && !$outerTransaction) {
                        // Queue failure logs must not contain SQL bindings with user traffic.
                        $state = (string) ($error->errorInfo[0] ?? $error->getCode());
                        $reason = preg_match('/^[A-Z0-9]{5}$/D', $state) === 1 ? " (SQLSTATE $state)" : '';
                        throw new RuntimeException('Statistics batch write failed' . $reason . '.');
                    }

                    throw $error;
                }

                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }

    private function additiveUpdates(Connection $db, string $table): array
    {
        $grammar = $db->getQueryGrammar();
        $driver = $db->getDriverName();
        $incoming = match ($driver) {
            'mysql', 'mariadb' => $db->getConfig('use_upsert_alias')
                ? fn (string $column) => $grammar->wrap('laravel_upsert_alias') . '.' . $column
                : fn (string $column) => 'values(' . $column . ')',
            'pgsql', 'sqlite' => fn (string $column) => 'excluded.' . $column,
            default => throw new RuntimeException('Unsupported statistics database driver.'),
        };
        $updates = ['updated_at'];
        foreach (['u', 'd'] as $name) {
            $column = $grammar->wrap($name);
            $updates[$name] = new Expression($grammar->wrapTable($table) . '.' . $column . ' + ' . $incoming($column));
        }

        if ($table === 'v2_stat_user_node_day') {
            $updates[] = 'server_type';
            $updates[] = 'server_name';
        }

        return $updates;
    }
}
