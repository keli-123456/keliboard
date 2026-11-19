<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\ExampleRehearsal;

final class Wire
{
    public const SOCKET = '/run/keli-failover-example/worker.sock';
    public const ACTIONS = ['v2', 'dns', 'stop'];
    public const PHASES = ['ready', 'v2_passed', 'passed', 'stopping', 'stopped', 'failed'];
    public const REASONS = ['expired', 'timeout', 'operator_stop', 'verification_failed'];
    public const ERRORS = ['busy', 'phase_conflict', 'closed', 'unavailable'];
    public const MAX_REQUEST = 256;
    public const MAX_REPLY = 2048;

    public static function request(Grant $grant, string $action): string
    {
        return json_encode(['grant' => $grant->fingerprint(), 'action' => $action], JSON_THROW_ON_ERROR) . "\n";
    }

    public static function decode(string $line, Grant $grant): ?string
    {
        if (strlen($line) > self::MAX_REQUEST || !str_ends_with($line, "\n")) return null;
        $message = json_decode($line, true, 4);
        if (!is_array($message) || count($message) !== 2 || !is_string($message['grant'] ?? null)
            || !hash_equals($grant->fingerprint(), $message['grant'])) return null;
        $action = $message['action'] ?? null;
        return in_array($action, ['status', ...self::ACTIONS], true) ? $action : null;
    }

    public static function report(string $phase, ?string $reason, bool $v2, bool $dns): array
    {
        return ['service' => 'lab-failover-01', 'phase' => $phase, 'reason' => $reason, 'simulation_only' => true, 'live_operations' => 0,
            'v2' => $v2 ? ['failure_rounds' => 3, 'candidate_creates' => 1, 'b_unchanged' => true] : null,
            'dns' => $dns ? ['a_updated' => 4, 'b_unchanged' => 4, 'receipt_recovered' => true] : null];
    }

    public static function checked(mixed $message): ?array
    {
        if (!is_array($message)) return null;
        if (count($message) === 1 && in_array($message['error'] ?? null, self::ERRORS, true)) return $message;
        $phase = $message['phase'] ?? null;
        $reason = $message['reason'] ?? null;
        $v2 = isset($message['v2']);
        $dns = isset($message['dns']);
        if (!in_array($phase, self::PHASES, true) || ($reason !== null && !in_array($reason, self::REASONS, true))
            || ($dns && !$v2) || ($phase === 'passed' && (!$dns || $reason !== null))
            || ($phase === 'v2_passed' && (!$v2 || $dns || $reason !== null))
            || ($phase === 'ready' && ($v2 || $reason !== null))
            || (in_array($phase, ['stopping', 'stopped', 'failed'], true) && ($dns || $reason === null))) return null;
        $safe = self::report($phase, $reason, $v2, $dns);
        return $safe === $message ? $safe : null;
    }
}
