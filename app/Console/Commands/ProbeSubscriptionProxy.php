<?php

namespace App\Console\Commands;

use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use Illuminate\Console\Command;

class ProbeSubscriptionProxy extends Command
{
    protected $signature = 'subscription-proxy:probe {--machine-id= : Probe one machine only}';

    protected $description = 'Probe subscription proxy machines through the real HTTPS reverse-proxy path';

    public function handle(SubscriptionProxyProbeService $service): int
    {
        $machineId = $this->option('machine-id');
        $results = $service->probeAll(is_numeric($machineId) ? (int) $machineId : null);
        $ok = 0;
        $failed = 0;
        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'ok') {
                $ok++;
            } else {
                $failed++;
            }
        }

        $this->info(sprintf('Subscription proxy probe finished: ok=%d failed=%d total=%d', $ok, $failed, count($results)));

        return self::SUCCESS;
    }
}
