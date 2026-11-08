<?php
namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverTriggerPreview;
use Illuminate\Console\Command;

class PreviewMachineFailover extends Command
{
    protected $signature = 'machines:preview-failover {--limit=5}';
    protected $description = 'Audit cache-only automatic trigger forecasts; never enqueue an execution';
    public function handle(MachineFailoverTriggerPreview $preview): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($limit === false) return self::INVALID;
        $this->line(json_encode($preview->collect($limit), JSON_THROW_ON_ERROR)); return self::SUCCESS;
    }
}
