<?php

namespace App\Console\Commands;

use App\Services\NodeRealtime\NodeRealtimeAuthenticator;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class WsServer extends Command
{
    protected $signature = 'ws-server {action=start : start|stop|restart|reload|status|connections} {--d : Run as daemon}';

    protected $description = 'Run the node realtime websocket server';

    public function handle(): int
    {
        if (!class_exists(\Workerman\Worker::class) || !class_exists(\Workerman\Timer::class)) {
            $this->error('workerman/workerman is not installed. Run composer install first.');
            return self::FAILURE;
        }

        $action = strtolower((string) $this->argument('action'));
        if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'status', 'connections'], true)) {
            $this->error('Unsupported ws-server action');
            return self::FAILURE;
        }

        $storagePath = storage_path('app/ws-server');
        File::ensureDirectoryExists($storagePath);

        $settings = app(NodeRealtimeSettings::class);
        $host = $settings->listenHost();
        $port = $settings->listenPort();
        $snapshotPath = $storagePath . '/connections.json';
        $receiptPath = $storagePath . '/receipts.json';
        $redisConnection = $settings->redisConnection();
        $redisQueue = $settings->redisQueue();
        $pingInterval = $settings->pingInterval();
        $realtimeEnabled = $settings->enabled();
        $queueBatchSize = 20;
        $receiptState = $this->loadReceiptState($receiptPath);

        if ($action === 'status') {
            return $this->renderStatus($snapshotPath, $host, $port, $realtimeEnabled);
        }

        if ($action === 'connections') {
            return $this->renderConnections($snapshotPath, $host, $port, $realtimeEnabled);
        }

        $workerClass = \Workerman\Worker::class;
        $timerClass = \Workerman\Timer::class;

        $workerClass::$pidFile = $storagePath . '/workerman.pid';
        $workerClass::$statusFile = $storagePath . '/workerman.status';
        $workerClass::$stdoutFile = $storagePath . '/workerman.log';
        $workerClass::$logFile = $storagePath . '/workerman-error.log';

        $server = new $workerClass("websocket://{$host}:{$port}");
        $server->name = 'xboard-node-realtime';
        $server->count = 1;
        $server->reusePort = false;

        $authenticator = app(NodeRealtimeAuthenticator::class);
        $connections = [];

        $server->onWorkerStart = function () use (
            &$connections,
            &$receiptState,
            $pingInterval,
            $timerClass,
            $realtimeEnabled,
            $redisConnection,
            $redisQueue,
            $queueBatchSize,
            $snapshotPath,
            $receiptPath,
            $host,
            $port
        ) {
            $this->writeSnapshot($snapshotPath, $host, $port, $realtimeEnabled, $connections);
            $this->writeReceiptSnapshot($receiptPath, $receiptState);

            $timerClass::add(0.5, function () use (&$connections, $realtimeEnabled, $redisConnection, $redisQueue, $queueBatchSize) {
                if (!$realtimeEnabled) {
                    return;
                }

                try {
                    $redis = Redis::connection($redisConnection);
                    for ($i = 0; $i < $queueBatchSize; $i++) {
                        $payload = $redis->lpop($redisQueue);
                        if ($payload === null) {
                            break;
                        }

                        $message = (string) $payload;
                        $decoded = json_decode($message, true);
                        foreach ($connections as $id => $connection) {
                            if (!(bool) ($connection->xboard_authenticated ?? false)) {
                                continue;
                            }
                            if (!$this->shouldDeliverMessage($connection, is_array($decoded) ? $decoded : null)) {
                                continue;
                            }
                            try {
                                $connection->send($message);
                            } catch (\Throwable) {
                                unset($connections[$id]);
                                try {
                                    $connection->close();
                                } catch (\Throwable) {
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Node realtime queue drain failed', ['error' => $e->getMessage()]);
                }
            });

            $timerClass::add(15, function () use (&$connections, $snapshotPath, $host, $port, $realtimeEnabled) {
                $this->writeSnapshot($snapshotPath, $host, $port, $realtimeEnabled, $connections);
            });

            $timerClass::add($pingInterval, function () use (&$connections, $realtimeEnabled) {
                if (!$realtimeEnabled) {
                    foreach ($connections as $id => $connection) {
                        unset($connections[$id]);
                        try {
                            $connection->close();
                        } catch (\Throwable) {
                        }
                    }
                    return;
                }

                $payload = json_encode([
                    'type' => 'ping',
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($payload === false) {
                    return;
                }

                foreach ($connections as $id => $connection) {
                    if (!(bool) ($connection->xboard_authenticated ?? false)) {
                        continue;
                    }
                    try {
                        $connection->send($payload);
                    } catch (\Throwable) {
                        unset($connections[$id]);
                        try {
                            $connection->close();
                        } catch (\Throwable) {
                        }
                    }
                }
            });
        };

        $server->onMessage = function ($connection, $message) use ($authenticator, &$connections, &$receiptState, $realtimeEnabled, $snapshotPath, $receiptPath, $host, $port) {
            if (!$realtimeEnabled) {
                $connection->close();
                return;
            }

            $payload = json_decode((string) $message, true);
            if (!is_array($payload)) {
                if (!(bool) ($connection->xboard_authenticated ?? false)) {
                    $connection->close();
                }
                return;
            }

            if (!(bool) ($connection->xboard_authenticated ?? false)) {
                try {
                    $auth = $authenticator->authenticate([
                        'token' => $payload['token'] ?? null,
                        'node_id' => $payload['node_id'] ?? null,
                        'node_type' => $payload['node_type'] ?? null,
                        'machine_id' => $payload['machine_id'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Node realtime authentication failed with exception', [
                        'error' => $e->getMessage(),
                        'remote_ip' => $this->resolveRemoteIp($connection),
                        'node_id' => $payload['node_id'] ?? null,
                        'node_type' => $payload['node_type'] ?? null,
                        'machine_id' => $payload['machine_id'] ?? null,
                    ]);
                    $connection->close();
                    return;
                }

                if (!$auth) {
                    Log::warning('Node realtime authentication failed', [
                        'remote_ip' => $this->resolveRemoteIp($connection),
                        'node_id' => $payload['node_id'] ?? null,
                        'node_type' => $payload['node_type'] ?? null,
                        'machine_id' => $payload['machine_id'] ?? null,
                    ]);
                    $connection->send(json_encode([
                        'type' => 'error',
                        'message' => 'authentication failed',
                        'ts' => time(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $connection->close();
                    return;
                }

                $connection->xboard_authenticated = true;
                $connection->xboard_authenticated_at = time();
                $serverInfo = $auth['server'] ?? null;
                $connection->xboard_connection_key = $auth['connection_key'];
                $connection->xboard_input_node_id = (string) $auth['input_node_id'];
                $connection->xboard_server_id = $serverInfo ? (int) $serverInfo->id : 0;
                $connection->xboard_machine_id = isset($auth['machine']) ? (int) $auth['machine']->id : null;
                $connection->xboard_node_type = $auth['is_v2node'] ? 'v2node' : ($auth['normalized_node_type'] ?? null);
                $connection->xboard_is_v2node = (bool) $auth['is_v2node'];
                $connection->xboard_group_ids = $this->normalizeIntList((array) ($serverInfo->group_ids ?? []));
                $connections[$connection->id] = $connection;

                Log::info('Node realtime authenticated', [
                    'connection_id' => $connection->id,
                    'remote_ip' => $this->resolveRemoteIp($connection),
                    'node_id' => (string) $auth['input_node_id'],
                    'server_id' => $connection->xboard_server_id,
                    'machine_id' => $connection->xboard_machine_id,
                    'node_type' => $connection->xboard_node_type,
                    'group_ids' => $connection->xboard_group_ids,
                ]);

                $connection->send(json_encode([
                    'type' => 'hello_ack',
                    'node_id' => (string) $auth['input_node_id'],
                    'server_id' => $connection->xboard_server_id,
                    'machine_id' => $connection->xboard_machine_id,
                    'node_type' => $connection->xboard_node_type,
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (array_key_exists('health', $payload)) {
                $connection->xboard_health = $this->normalizeHealthPayload($payload['health']);
            }

            if ((bool) ($connection->xboard_authenticated ?? false)) {
                $this->writeSnapshot($snapshotPath, $host, $port, $realtimeEnabled, $connections);
            }

            if (($payload['type'] ?? null) === 'ping') {
                $connection->send(json_encode([
                    'type' => 'pong',
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return;
            }

            if (($payload['type'] ?? null) === 'receipt') {
                $this->recordReceipt($receiptState, $receiptPath, $connection, $payload);
            }
        };

        $server->onClose = function ($connection) use (&$connections, $snapshotPath, $host, $port, $realtimeEnabled) {
            if ((bool) ($connection->xboard_authenticated ?? false)) {
                Log::info('Node realtime disconnected', [
                    'connection_id' => $connection->id,
                    'remote_ip' => $this->resolveRemoteIp($connection),
                    'node_id' => (string) ($connection->xboard_input_node_id ?? ''),
                    'server_id' => (int) ($connection->xboard_server_id ?? 0),
                    'machine_id' => $connection->xboard_machine_id ?? null,
                    'node_type' => $connection->xboard_node_type ?? null,
                ]);
            }
            unset($connections[$connection->id]);
            $this->writeSnapshot($snapshotPath, $host, $port, $realtimeEnabled, $connections);
        };

        global $argv;
        $argv = array_values(array_filter([
            base_path('artisan'),
            $action,
            $this->option('d') ? '-d' : null,
        ]));

        $this->line("ws-server action={$action} listen={$host}:{$port}");
        $workerClass::runAll();

        return self::SUCCESS;
    }

    private function shouldDeliverMessage(object $connection, ?array $message): bool
    {
        if (!is_array($message)) {
            return true;
        }

        $targets = $message['targets'] ?? null;
        if (!is_array($targets)) {
            return true;
        }

        $serverIds = $this->normalizeIntList((array) ($targets['server_ids'] ?? []));
        $groupIds = $this->normalizeIntList((array) ($targets['group_ids'] ?? []));
        $machineIds = $this->normalizeIntList((array) ($targets['machine_ids'] ?? []));
        if ($serverIds === [] && $groupIds === [] && $machineIds === []) {
            return true;
        }

        $serverId = (int) ($connection->xboard_server_id ?? 0);
        if ($serverIds !== [] && in_array($serverId, $serverIds, true)) {
            return true;
        }

        $machineId = (int) ($connection->xboard_machine_id ?? 0);
        if ($machineIds !== [] && in_array($machineId, $machineIds, true)) {
            return true;
        }

        $connectionGroupIds = $this->normalizeIntList((array) ($connection->xboard_group_ids ?? []));
        if ($groupIds !== [] && array_intersect($groupIds, $connectionGroupIds) !== []) {
            return true;
        }

        return false;
    }

    private function writeSnapshot(string $snapshotPath, string $host, int $port, bool $enabled, array $connections): void
    {
        $rows = [];
        foreach ($connections as $connection) {
            if (!(bool) ($connection->xboard_authenticated ?? false)) {
                continue;
            }

            $rows[] = [
                'connection_id' => (int) ($connection->id ?? 0),
                'remote_ip' => $this->resolveRemoteIp($connection),
                'node_id' => (string) ($connection->xboard_input_node_id ?? ''),
                'server_id' => (int) ($connection->xboard_server_id ?? 0),
                'machine_id' => $connection->xboard_machine_id ?? null,
                'node_type' => $connection->xboard_node_type ?? null,
                'group_ids' => $this->normalizeIntList((array) ($connection->xboard_group_ids ?? [])),
                'authenticated_at' => $this->formatTimestamp((int) ($connection->xboard_authenticated_at ?? 0)),
                'health' => $this->normalizeHealthPayload($connection->xboard_health ?? null),
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return [$left['server_id'], $left['node_id']] <=> [$right['server_id'], $right['node_id']];
        });

        $payload = json_encode([
            'enabled' => $enabled,
            'listen' => "{$host}:{$port}",
            'updated_at' => date(DATE_ATOM),
            'active_connections' => count($rows),
            'connections' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($payload === false) {
            return;
        }

        try {
            File::put($snapshotPath, $payload);
        } catch (\Throwable $e) {
            Log::warning('Node realtime snapshot write failed', ['error' => $e->getMessage()]);
        }
    }

    private function recordReceipt(array &$receiptState, string $receiptPath, object $connection, array $payload): void
    {
        if (!(bool) ($connection->xboard_authenticated ?? false)) {
            return;
        }

        $topic = trim((string) ($payload['topic'] ?? ''));
        if (!in_array($topic, ['config', 'users'], true)) {
            return;
        }

        $serverId = (int) ($connection->xboard_server_id ?? 0);
        if ($serverId <= 0) {
            return;
        }

        $receiptState[$this->receiptStateKey($serverId, $topic)] = [
            'server_id' => $serverId,
            'node_id' => (string) ($connection->xboard_input_node_id ?? ''),
            'node_type' => $connection->xboard_node_type ?? null,
            'topic' => $topic,
            'event_id' => trim((string) ($payload['event_id'] ?? '')),
            'reason' => trim((string) ($payload['reason'] ?? '')),
            'status' => trim((string) ($payload['status'] ?? '')),
            'message' => trim((string) ($payload['message'] ?? '')),
            'updated_at' => date(DATE_ATOM),
        ];

        Log::info('Node realtime receipt recorded', [
            'server_id' => $serverId,
            'node_id' => (string) ($connection->xboard_input_node_id ?? ''),
            'topic' => $topic,
            'status' => trim((string) ($payload['status'] ?? '')),
            'event_id' => trim((string) ($payload['event_id'] ?? '')),
        ]);

        $this->writeReceiptSnapshot($receiptPath, $receiptState);
    }

    private function receiptStateKey(int $serverId, string $topic): string
    {
        return $serverId . ':' . $topic;
    }

    private function loadReceiptState(string $receiptPath): array
    {
        $snapshot = $this->loadSnapshot($receiptPath);
        $state = [];

        foreach ((array) ($snapshot['receipts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $serverId = (int) ($row['server_id'] ?? 0);
            $topic = trim((string) ($row['topic'] ?? ''));
            if ($serverId <= 0 || $topic === '') {
                continue;
            }

            $state[$this->receiptStateKey($serverId, $topic)] = [
                'server_id' => $serverId,
                'node_id' => (string) ($row['node_id'] ?? ''),
                'node_type' => $row['node_type'] ?? null,
                'topic' => $topic,
                'event_id' => (string) ($row['event_id'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $state;
    }

    private function writeReceiptSnapshot(string $receiptPath, array $receiptState): void
    {
        $rows = array_values(array_map(function (array $row): array {
            return [
                'server_id' => (int) ($row['server_id'] ?? 0),
                'node_id' => (string) ($row['node_id'] ?? ''),
                'node_type' => $row['node_type'] ?? null,
                'topic' => (string) ($row['topic'] ?? ''),
                'event_id' => (string) ($row['event_id'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $receiptState));

        usort($rows, function (array $left, array $right): int {
            return [$left['server_id'], $left['topic']] <=> [$right['server_id'], $right['topic']];
        });

        $payload = json_encode([
            'updated_at' => date(DATE_ATOM),
            'receipts' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($payload === false) {
            return;
        }

        try {
            File::put($receiptPath, $payload);
        } catch (\Throwable $e) {
            Log::warning('Node realtime receipt snapshot write failed', ['error' => $e->getMessage()]);
        }
    }

    private function renderStatus(string $snapshotPath, string $host, int $port, bool $enabled): int
    {
        $snapshot = $this->loadSnapshot($snapshotPath);
        $rows = [
            ['enabled', $enabled ? 'yes' : 'no'],
            ['listen', "{$host}:{$port}"],
            ['active_connections', (string) ($snapshot['active_connections'] ?? 0)],
            ['updated_at', (string) ($snapshot['updated_at'] ?? '-')],
        ];

        $this->table(['key', 'value'], $rows);
        return self::SUCCESS;
    }

    private function renderConnections(string $snapshotPath, string $host, int $port, bool $enabled): int
    {
        $snapshot = $this->loadSnapshot($snapshotPath);
        $rows = [];
        foreach ((array) ($snapshot['connections'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                (string) ($row['connection_id'] ?? ''),
                (string) ($row['remote_ip'] ?? ''),
                (string) ($row['node_id'] ?? ''),
                (string) ($row['server_id'] ?? ''),
                (string) ($row['machine_id'] ?? ''),
                (string) ($row['node_type'] ?? ''),
                implode(',', (array) ($row['group_ids'] ?? [])),
                (string) ($row['authenticated_at'] ?? ''),
                $this->renderHealthSummary((array) ($row['health'] ?? [])),
            ];
        }

        $this->line(sprintf(
            'enabled=%s listen=%s:%d active_connections=%d updated_at=%s',
            $enabled ? 'yes' : 'no',
            $host,
            $port,
            (int) ($snapshot['active_connections'] ?? 0),
            (string) ($snapshot['updated_at'] ?? '-')
        ));

        if ($rows === []) {
            $this->line('No authenticated realtime connections.');
            return self::SUCCESS;
        }

        $this->table(
            ['connection_id', 'remote_ip', 'node_id', 'server_id', 'machine_id', 'node_type', 'group_ids', 'authenticated_at', 'health'],
            $rows
        );

        return self::SUCCESS;
    }

    private function normalizeHealthPayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $runtime = is_array($payload['runtime'] ?? null) ? $payload['runtime'] : [];

        return [
            'status' => ($status = trim((string) ($payload['status'] ?? ''))) !== '' ? $status : null,
            'ready' => $this->toBool($payload['ready'] ?? false),
            'version' => ($version = trim((string) ($payload['version'] ?? ''))) !== '' ? $version : null,
            'config_path' => ($configPath = trim((string) ($payload['config_path'] ?? ''))) !== '' ? $configPath : null,
            'started_at' => ($startedAt = trim((string) ($payload['started_at'] ?? ''))) !== '' ? $startedAt : null,
            'uptime_seconds' => max(0, (int) ($payload['uptime_seconds'] ?? 0)),
            'last_reload_at' => ($lastReloadAt = trim((string) ($payload['last_reload_at'] ?? ''))) !== '' ? $lastReloadAt : null,
            'node_count' => max(0, (int) ($payload['node_count'] ?? 0)),
            'realtime_enabled' => $this->toBool($payload['realtime_enabled'] ?? false),
            'health_port' => max(0, (int) ($payload['health_port'] ?? 0)),
            'goroutines' => max(0, (int) ($payload['goroutines'] ?? 0)),
            'runtime' => [
                'gomemlimit' => ($goMemLimit = trim((string) ($runtime['gomemlimit'] ?? ''))) !== '' ? $goMemLimit : null,
                'gomemlimit_bytes' => max(0, (int) ($runtime['gomemlimit_bytes'] ?? 0)),
                'gogc' => (int) ($runtime['gogc'] ?? 0),
            ],
            'updated_at' => ($updatedAt = trim((string) ($payload['updated_at'] ?? ''))) !== '' ? $updatedAt : null,
        ];
    }

    private function renderHealthSummary(array $health): string
    {
        $status = trim((string) ($health['status'] ?? ''));
        $ready = array_key_exists('ready', $health) ? ($this->toBool($health['ready']) ? 'ready' : 'not-ready') : '';
        $parts = array_values(array_filter([$status, $ready], fn ($part) => $part !== ''));

        return $parts !== [] ? implode(' / ', $parts) : '-';
    }

    private function loadSnapshot(string $snapshotPath): array
    {
        if (!File::exists($snapshotPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) File::get($snapshotPath), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveRemoteIp(object $connection): ?string
    {
        try {
            if (method_exists($connection, 'getRemoteIp')) {
                $remoteIp = $connection->getRemoteIp();
                if (is_string($remoteIp) && $remoteIp !== '') {
                    return $remoteIp;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function formatTimestamp(int $timestamp): ?string
    {
        if ($timestamp <= 0) {
            return null;
        }

        return date(DATE_ATOM, $timestamp);
    }

    private function normalizeIntList(array $values): array
    {
        $normalized = array_map(
            fn ($value) => (int) $value,
            array_filter($values, fn ($value) => is_numeric($value))
        );

        $normalized = array_values(array_unique(array_filter($normalized, fn (int $value) => $value > 0)));
        sort($normalized);

        return $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
