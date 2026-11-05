<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\MachineOperationNotifier;
use Illuminate\Console\Command;

class AdvanceMachineOperationNotices extends Command
{
    protected $signature = 'machines:advance-notices';
    protected $description = 'Dispatch notification outbox entries without operating machines or DNS';
    public function handle(MachineOperationNotifier $notifier): int
    {
        $this->line(json_encode($notifier->advance()));
        return self::SUCCESS;
    }
}
