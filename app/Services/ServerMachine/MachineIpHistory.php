<?php

namespace App\Services\ServerMachine;

class MachineIpHistory
{
    public const LIMIT = 20;
    private const SOURCES = ['public_ipv4', 'public_ipv6', 'panel_seen'];

    public function observe(array $previous, array $reported, int $now): array
    {
        $saved = is_array($previous['ip_observation'] ?? null) ? $previous['ip_observation'] : [];
        $current = [];
        foreach (self::SOURCES as $source) {
            $value = $this->address(data_get($saved, "current.$source") ?? data_get($previous, "ip.$source"), $source);
            if ($value !== null) {
                $current[$source] = $value;
            }
        }
        $history = array_slice(array_values(array_filter((array) ($saved['changes'] ?? []), function ($row): bool {
            return is_array($row) && in_array($row['source'] ?? null, self::SOURCES, true)
                && $this->address($row['from'] ?? null, $row['source']) !== null
                && $this->address($row['to'] ?? null, $row['source']) !== null
                && is_numeric($row['at'] ?? null);
        })), -self::LIMIT);

        foreach (self::SOURCES as $source) {
            $next = $this->address($reported[$source] ?? null, $source);
            // Missing reports are not address removals. Keep the last valid baseline.
            if ($next === null) {
                continue;
            }
            if (isset($current[$source]) && $current[$source] !== $next) {
                $history[] = ['source' => $source, 'from' => $current[$source], 'to' => $next, 'at' => $now];
            }
            $current[$source] = $next;
        }

        return [
            'tracking_since' => max(1, (int) ($saved['tracking_since'] ?? $now)),
            'current' => $current,
            'changes' => array_slice($history, -self::LIMIT),
        ];
    }

    private function address(mixed $value, string $source): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $flags = match ($source) {
            'public_ipv4' => FILTER_FLAG_IPV4,
            'public_ipv6' => FILTER_FLAG_IPV6,
            default => 0,
        };
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP, $flags) ? inet_ntop(inet_pton($value)) : null;
    }
}
