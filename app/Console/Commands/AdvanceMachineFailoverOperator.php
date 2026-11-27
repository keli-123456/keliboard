<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use Illuminate\Console\Command;

class AdvanceMachineFailoverOperator extends Command
{
    protected $signature = 'machines:advance-operator-failover {--stops-only}';
    protected $description = 'Recover a previously authorized manual failover ticket; never start a new run';

    public function handle(MachineFailoverRunner $runner): int
    {
        $this->line(json_encode($runner->advanceOperator((bool) $this->option('stops-only')), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
