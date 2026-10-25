<?php

namespace App\Console\Commands;

use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Services\SubscriptionProxy\WebsiteProxyProbeService;
use Illuminate\Console\Command;

class ProbeWebsiteProxy extends Command
{
    protected $signature = 'website-proxy:probe {--machine-id= : Probe one configured machine only}';

    protected $description = 'Check website HTTPS pages and site identity before subscription publication';

    public function handle(WebsiteProxyProbeService $service, WebsiteProxyEndpointService $endpoints): int
    {
        if (!$endpoints->healthTableExists()) {
            $this->error('Website proxy health table is unavailable; run migrations before enabling publication.');
            return self::FAILURE;
        }
        $id = $this->option('machine-id');
        if ($id !== null && (!ctype_digit((string) $id) || (int) $id <= 0)) {
            $this->error('machine-id must be a positive integer');
            return self::FAILURE;
        }
        $results = $service->probeAll($id === null ? null : (int) $id);
        $ok = count(array_filter($results, fn (array $row): bool => ($row['status'] ?? '') === 'ok'));
        $this->info(sprintf('Website proxy probe finished: ok=%d other=%d total=%d', $ok, count($results) - $ok, count($results)));

        return self::SUCCESS;
    }
}
