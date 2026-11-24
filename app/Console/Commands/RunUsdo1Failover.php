<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineUsdo1TimedExecution;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

final class RunUsdo1Failover extends Command
{
    protected $signature = 'machines:usdo1-failover {operation} {grant} {--admin=} {--plan=}';
    protected $description = 'Operate the existing signed, timed failover grant for service 123';

    public function handle(MachineUsdo1TimedExecution $entry): int
    {
        $operation = $this->argument('operation');
        $grant = $this->argument('grant');
        $admin = filter_var($this->option('admin'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plan = $this->option('plan');
        $planId = filter_var($plan, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (PHP_SAPI !== 'cli' || !is_string($grant) || !$admin || !in_array($operation, ['start', 'status', 'stop'], true)
            || ($operation === 'start' ? !$planId : $plan !== null)) {
            $this->error('invalid_usdo1_command');
            return self::INVALID;
        }
        try {
            $result = match ($operation) {
                'start' => $entry->start($grant, $planId, $admin),
                'status' => $entry->status($grant, $admin),
                'stop' => $entry->stop($grant, $admin),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (CloudException | LockTimeoutException $error) {
            $reason = $error->getMessage();
            $this->error(preg_match('/^[a-z_]{1,64}$/D', $reason) ? $reason : 'usdo1_state_unconfirmed');
        } catch (\Throwable) {
            $this->error('usdo1_state_unconfirmed');
        }
        return self::FAILURE;
    }
}
