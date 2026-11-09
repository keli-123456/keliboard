<?php
namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverAdmission;
use Illuminate\Console\Command;

class AdmitMachineFailover extends Command
{
    protected $signature = 'machines:admit-failover {--limit=5}';
    protected $description = 'Admit provider-pinned fault episodes through the existing closed execution gate';

    public function handle(MachineFailoverAdmission $admission): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($limit === false) return self::INVALID;
        $this->line(json_encode($admission->advance($limit), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
