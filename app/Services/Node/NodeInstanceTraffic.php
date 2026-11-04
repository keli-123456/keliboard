<?php

namespace App\Services\Node;

use App\Services\TrafficBatchPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NodeInstanceTraffic
{
    public function totals(array $traffic, int $rate): array
    {
        $up = 0; $down = 0;
        foreach ($traffic as [$u, $d]) {
            $u = TrafficBatchPayload::scale($u, $rate); $d = TrafficBatchPayload::scale($d, $rate);
            if ($up > PHP_INT_MAX - $u || $down > PHP_INT_MAX - $d) throw new \OverflowException('Instance traffic total exceeds integer bounds.');
            $up += $u; $down += $d;
        }
        return [$up, $down];
    }

    public function apply(array $payload): void
    {
        $key = NodeReportSource::validKey($payload['reporter_key'] ?? null);
        if (!$key || !Schema::hasTable('v2_server_instance_traffic')) return;
        [$up, $down] = $this->totals($payload['traffic'], $payload['rate_cents']);
        $identity = ['server_id' => $payload['node_id'], 'reporter_key' => $key, 'record_at' => $payload['record_at']];
        DB::table('v2_server_instance_traffic')->insertOrIgnore($identity + ['upload' => 0, 'download' => 0, 'updated_at' => time()]);
        DB::table('v2_server_instance_traffic')->where($identity)->update([
            'upload' => DB::raw('upload + ' . $up), 'download' => DB::raw('download + ' . $down), 'updated_at' => time(),
        ]);
    }
}
