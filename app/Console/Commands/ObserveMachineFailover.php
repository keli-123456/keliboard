<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use Illuminate\Console\Command;

class ObserveMachineFailover extends Command
{
    protected $signature = 'machines:observe-failover';
    protected $description = 'Record independent failover observations and blocked plans; never execute them';

    public function handle(MachineFailoverObserver $observer): int
    {
        $this->line(json_encode($observer->collect(), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
