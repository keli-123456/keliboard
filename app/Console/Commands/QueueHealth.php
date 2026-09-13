<?php

namespace App\Console\Commands;

use App\Services\QueueConsumerHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueHealth extends Command
{
    protected $signature = 'xboard:queue-health {--local : Check only this host} {--wait=0 : Startup grace in seconds, maximum 120} {--log : Log unhealthy queue consumers}';
    protected $description = 'Verify that every configured Horizon queue has a running consumer';

    public function handle(QueueConsumerHealth $health): int
    {
        $deadline = microtime(true) + min(120, max(0, (int) $this->option('wait')));
        do {
            try {
                $result = $health->snapshot((bool) $this->option('local'));
            } catch (Throwable $e) {
                $result = ['healthy' => false, 'error_class' => get_class($e)];
            }
            if ($result['healthy'] || microtime(true) >= $deadline) {
                break;
            }
            sleep(1);
        } while (true);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        if (!$result['healthy'] && $this->option('log')) {
            Log::error('Queue consumers are unhealthy', $result);
        }
        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
