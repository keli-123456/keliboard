<?php

namespace App\Services\NodeRealtime;

class NodeRealtimeSettings
{
    public function enabledSetting(): bool
    {
        return $this->toBool(
            admin_setting('node_realtime_enable', config('node_realtime.enabled', true)),
            (bool) config('node_realtime.enabled', true)
        );
    }

    public function enabled(): bool
    {
        return (bool) config('node_realtime.enabled', true) && $this->enabledSetting();
    }

    public function listenHost(): string
    {
        $host = trim((string) config('node_realtime.host', '0.0.0.0'));
        return $host !== '' ? $host : '0.0.0.0';
    }

    public function listenPort(): int
    {
        return $this->toPositiveInt(config('node_realtime.port', 7002), 7002);
    }

    public function path(): string
    {
        $configured = trim((string) admin_setting('node_realtime_path', config('node_realtime.path', '/ws/node')));
        if ($configured === '') {
            $configured = (string) config('node_realtime.path', '/ws/node');
        }

        $configured = '/' . ltrim($configured, '/');
        return $configured !== '/' ? $configured : '/ws/node';
    }

    public function configuredPublicUrl(): string
    {
        return trim((string) admin_setting('node_realtime_public_url', config('node_realtime.public_url', '')));
    }

    public function publicPort(): int
    {
        return $this->toPositiveInt(
            admin_setting(
                'node_realtime_public_port',
                config('node_realtime.public_port', config('node_realtime.port', 7002))
            ),
            $this->listenPort()
        );
    }

    public function pingInterval(): int
    {
        return max(
            5,
            $this->toPositiveInt(
                admin_setting('node_realtime_ping_interval', config('node_realtime.ping_interval', 30)),
                30
            )
        );
    }

    public function redisConnection(): string
    {
        return trim((string) config('node_realtime.redis.connection', 'default')) ?: 'default';
    }

    public function redisQueue(): string
    {
        return trim((string) config('node_realtime.redis.queue', 'xboard:node_realtime:events'))
            ?: 'xboard:node_realtime:events';
    }

    public function redisMaxLength(): int
    {
        return max(100, $this->toPositiveInt(config('node_realtime.redis.max_length', 10000), 10000));
    }

    public function resolvedPublicUrl(): string
    {
        $explicit = $this->configuredPublicUrl();
        if ($explicit !== '') {
            return $explicit;
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl === '') {
            return '';
        }

        $parts = parse_url($appUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = (($parts['scheme'] ?? 'http') === 'https') ? 'wss' : 'ws';
        $host = (string) $parts['host'];
        $port = $this->publicPort();
        $defaultPort = $scheme === 'wss' ? 443 : 80;
        $portSuffix = $port === $defaultPort ? '' : ':' . $port;

        return "{$scheme}://{$host}{$portSuffix}{$this->path()}";
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function toPositiveInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $normalized = (int) $value;
        return $normalized > 0 ? $normalized : $default;
    }
}
