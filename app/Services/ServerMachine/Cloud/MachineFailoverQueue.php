<?php

namespace App\Services\ServerMachine\Cloud;

final class MachineFailoverQueue
{
    public const CONNECTION = 'redis_failover';
    public const NAME = 'machine_failover';
    public const JOB_TIMEOUT = 75;
    public const RETRY_AFTER = 90;

    public static function supervisor(): array
    {
        return ['connection' => self::CONNECTION, 'queue' => [self::NAME], 'balance' => false,
            'minProcesses' => 1, 'maxProcesses' => 1, 'tries' => 1, 'timeout' => 80, 'sleep' => 1,
            'memory' => 128, 'maxJobs' => 100, 'maxTime' => 900, 'nice' => 10];
    }
}
