<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution;
use Illuminate\Support\Str;

final readonly class MachineUsdo1LaunchScope
{
    public const SERVICE = 1;
    public const CALLBACK_PATH = '/api/v2/server/machine/provision/result';
    public const RECOVERY_SECONDS = 2100;
    public string $origin;

    public function __construct(public string $grant, public int $admin, string $origin)
    {
        if (!Str::isUuid($grant) || strtolower($grant) !== $grant || $admin < 1) {
            throw new CloudException('usdo1_launch_scope_invalid');
        }
        $this->origin = (new ProvisionBootstrap())->origin($origin);
        if ($this->origin !== $origin || $origin === 'https://' . MachineUsdo1HandoverScope::NAME) {
            throw new CloudException('usdo1_callback_origin_invalid');
        }
    }

    public static function fromEnvironment(): self
    {
        $admin = filter_var(getenv('KELI_USDO1_ADMIN_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $grant = getenv('KELI_USDO1_GRANT_ID');
        $origin = getenv('KELI_USDO1_CALLBACK_ORIGIN');
        if (!$admin || !is_string($grant) || !is_string($origin)) throw new CloudException('usdo1_launch_unconfigured');
        return new self($grant, $admin, $origin);
    }

    public static function assertProcess(string $sapi): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== $sapi || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new CloudException('usdo1_launch_process_refused');
        }
    }

    public function assertIndependent(string $panelOrigin): void
    {
        if ($this->origin === $panelOrigin) throw new CloudException('usdo1_callback_not_independent');
    }

    public function assertExecution(MachineFailoverExecution $run): void
    {
        if ($run->service_id !== self::SERVICE || $run->admin_id !== $this->admin
            || ($run->payload['handover_grant_id'] ?? null) !== $this->grant) {
            throw new CloudException('usdo1_launch_execution_changed');
        }
    }
}
