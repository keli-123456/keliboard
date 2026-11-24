<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;
use Illuminate\Console\Command;

final class RunUsdo1Worker extends Command
{
    protected $signature = 'machines:usdo1-worker';
    protected $description = 'Advance one tick for the separately authorized service 123 grant';

    public function handle(): int
    {
        try {
            MachineUsdo1LaunchScope::assertProcess('cli');
            $scope = MachineUsdo1LaunchScope::fromEnvironment();
            $result = app(MachineUsdo1Runtime::class)->pulse($scope);
            $this->line(json_encode(array_intersect_key($result, array_flip(['status', 'runtime', 'expired', 'revoked', 'stop_requested'])), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $reason = $error instanceof CloudException ? $error->getMessage() : '';
            $this->error(preg_match('/^[a-z_]{1,64}$/D', $reason) ? $reason : 'usdo1_worker_unconfirmed');
            return self::FAILURE;
        }
    }
}
