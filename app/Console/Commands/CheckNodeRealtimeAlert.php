<?php

namespace App\Console\Commands;

use App\Services\NodeRealtime\NodeRealtimeAlertService;
use Illuminate\Console\Command;

class CheckNodeRealtimeAlert extends Command
{
    protected $signature = 'check:node-realtime-alert {--force : Ignore cooldown and evaluate as a fresh alert} {--notify : Force Telegram notify for this run}';

    protected $description = 'Check realtime sync status and dispatch alerts';

    public function handle(NodeRealtimeAlertService $alertService): int
    {
        $result = $alertService->dispatch(
            force: (bool) $this->option('force'),
            notifyOverride: (bool) $this->option('notify')
        );

        $alerts = (array) ($result['alerts'] ?? []);
        if ($alerts === []) {
            $this->line('No realtime alerts');
            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $title = (string) ($alert['title'] ?? 'Unknown alert');
            $message = (string) ($alert['message'] ?? '');
            $cooldownHit = (bool) ($alert['cooldown_hit'] ?? false);

            $this->warn($title . ($cooldownHit ? ' [cooldown]' : ''));
            if ($message !== '') {
                $this->line($message);
            }
        }

        if (($result['notified'] ?? false) === true) {
            $this->info('Telegram alert sent');
        } elseif (($result['notify_error'] ?? null) !== null) {
            $this->error('Telegram alert failed: ' . $result['notify_error']);
        }

        return self::SUCCESS;
    }
}
