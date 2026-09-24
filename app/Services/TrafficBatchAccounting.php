<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrafficBatchAccounting
{
    public function apply(array $payload): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Traffic accounting requires its receipt transaction.');
        }
        foreach ([
            'v2_stat_user' => ['user_id', 'server_rate', 'record_at', 'record_type'],
            'v2_stat_user_node_day' => ['user_id', 'server_id', 'server_rate', 'record_at', 'record_type'],
            'v2_stat_server' => ['server_id', 'server_type', 'record_at', 'record_type'],
        ] as $table => $key) {
            if (!DB::getSchemaBuilder()->hasIndex($table, $key, 'unique')) {
                throw new RuntimeException('Durable traffic requires the current statistics unique indexes.');
            }
        }
        $rows = TrafficBatchPayload::normalize($payload['traffic']);
        $rate = TrafficBatchPayload::rateString($payload['rate_cents']);
        $date = ['record_at' => $payload['record_at'], 'record_type' => 'd'];
        $now = time();
        $scaled = [];
        $rawUpload = $rawDownload = 0;
        // A fixed table/key order keeps concurrent batches from taking opposite row locks.
        foreach ($rows as $id => [$upload, $download]) {
            $u = TrafficBatchPayload::scale($upload, $payload['rate_cents']);
            $d = TrafficBatchPayload::scale($download, $payload['rate_cents']);
            $scaled[$id] = [$u, $d];
            $rawUpload = TrafficBatchPayload::add($rawUpload, $upload);
            $rawDownload = TrafficBatchPayload::add($rawDownload, $download);
            $query = DB::table('v2_user')->where('id', $id);
            $user = (clone $query)->lockForUpdate()->first(['u', 'd']);
            if ($user) {
                $query->update([
                    'u' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($user->u), $u),
                    'd' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($user->d), $d),
                    't' => $now,
                ]);
            }
        }
        foreach ($scaled as $id => [$u, $d]) {
            $this->incrementStat('v2_stat_user', $date + ['user_id' => $id, 'server_rate' => $rate], $u, $d, [], $now);
        }
        foreach ($scaled as $id => [$u, $d]) {
            $this->incrementStat('v2_stat_user_node_day', $date + [
                'user_id' => $id, 'server_id' => $payload['node_id'], 'server_rate' => $rate,
            ], $u, $d, [
                'server_type' => $payload['node_type'], 'server_name' => $payload['node_name'],
            ], $now);
        }
        $this->incrementStat('v2_stat_server', $date + [
            'server_id' => $payload['node_id'], 'server_type' => $payload['node_type'],
        ], $rawUpload, $rawDownload, [], $now);
    }

    private function incrementStat(string $table, array $key, int $u, int $d, array $metadata, int $now): void
    {
        // Upsert only a key onto conflict: never replay an increment in a fallback branch.
        DB::table($table)->upsert([$key + $metadata + [
            'u' => 0, 'd' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]], array_keys($key), [array_key_first($key)]);
        $query = DB::table($table)->where($key);
        $row = (clone $query)->lockForUpdate()->first(['u', 'd']);
        if (!$row) {
            throw new RuntimeException('Traffic statistics identity did not match its unique constraint.');
        }
        $query->update($metadata + [
            'u' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->u), $u),
            'd' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->d), $d),
            'updated_at' => $now,
        ]);
    }
}
