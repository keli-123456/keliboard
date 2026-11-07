<?php
namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use Illuminate\Console\Command;

class AdvanceMachineFailover extends Command
{
    protected $signature = 'machines:advance-native-failover {--stops-only}';
    protected $description = 'Recover existing native run tickets; never create a new failure intent';
    public function handle(MachineFailoverRunner $runner): int
    {
        $this->line(json_encode($runner->advance((bool) $this->option('stops-only')), JSON_THROW_ON_ERROR)); return self::SUCCESS;
    }
}
