<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorControl;
use Illuminate\Console\Command;

final class ControlMachineFailoverOperator extends Command
{
    protected $signature = 'machines:control-operator-failover {action}';
    protected $description = 'Verify, revoke and settle a pinned operator handover using a root-owned local policy';
    private const POLICY = '/etc/keliboard-failover/control.json';

    public function handle(MachineFailoverOperatorControl $control): int
    {
        try {
            if (PHP_OS_FAMILY !== 'Linux' || realpath(self::POLICY) !== self::POLICY)
                throw new CloudException('operator_control_unavailable');
            $this->protectedPath(dirname(self::POLICY), true);
            $this->protectedPath(self::POLICY, false);
            $policy = $this->decode(file_get_contents(self::POLICY, false, null, 0, 16385), 16384);
            $input = $this->decode(stream_get_contents(STDIN, 262145), 262144);
            $this->line(json_encode($control->execute($policy, $this->argument('action'), $input), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $code = $error instanceof CloudException && preg_match('/^[a-z0-9_]{1,100}$/D', $error->getMessage())
                ? $error->getMessage() : 'operator_control_failed';
            $this->line(json_encode(['error' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    private function protectedPath(string $path, bool $directory): void
    {
        $st = @lstat($path);
        if (!$st || $st['uid'] !== 0 || ($st['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($st['mode'] & 0022) !== 0 || (!$directory && ($st['mode'] & 0007) !== 0))
            throw new CloudException('operator_control_unavailable');
    }

    private function decode(string|false $data, int $limit): array
    {
        $decoded = is_string($data) && strlen($data) <= $limit ? json_decode($data, true, 20) : null;
        if (!is_array($decoded)) throw new CloudException('invalid_operator_control_request');
        return $decoded;
    }
}
