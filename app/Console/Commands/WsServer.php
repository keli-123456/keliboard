<?php

namespace App\Console\Commands;

use App\Services\NodeRealtime\NodeRealtimeAuthenticator;
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
        if (!config('node_realtime.enabled', false)) {
            $this->warn('node realtime server is disabled');
            return self::FAILURE;
        }

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

        $host = (string) config('node_realtime.host', '0.0.0.0');
        $port = max(1, (int) config('node_realtime.port', 7002));
        $redisConnection = (string) config('node_realtime.redis.connection', 'default');
        $redisQueue = (string) config('node_realtime.redis.queue', 'xboard:node_realtime:events');
        $pingInterval = max(5, (int) config('node_realtime.ping_interval', 30));

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

        $server->onWorkerStart = function () use (&$connections, $redisConnection, $redisQueue, $pingInterval, $timerClass) {
            $timerClass::add(0.5, function () use (&$connections, $redisConnection, $redisQueue) {
                try {
                    $redis = Redis::connection($redisConnection);
                    while (($payload = $redis->lpop($redisQueue)) !== null) {
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

            $timerClass::add($pingInterval, function () use (&$connections) {
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

        $server->onWebSocketConnect = function ($connection, $request) use (&$connections, $authenticator) {
            $params = [];
            try {
                $params = (array) $request->get();
            } catch (\Throwable) {
            }

            $auth = $authenticator->authenticate($params);
            if (!$auth) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'message' => 'authentication failed',
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $connection->close();
                return;
            }

            $connection->xboard_authenticated = true;
            $connection->xboard_connection_key = $auth['connection_key'];
            $connection->xboard_server_id = (int) $auth['server']->id;
            $connection->xboard_is_v2node = (bool) $auth['is_v2node'];
            $connection->xboard_group_ids = $this->normalizeIntList((array) ($auth['server']->group_ids ?? []));
            $connections[$connection->id] = $connection;

            $connection->send(json_encode([
                'type' => 'hello_ack',
                'node_id' => (string) $auth['input_node_id'],
                'server_id' => (int) $auth['server']->id,
                'node_type' => $auth['is_v2node'] ? 'v2node' : ($auth['normalized_node_type'] ?? null),
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };

        $server->onMessage = function ($connection, $message) {
            if (!(bool) ($connection->xboard_authenticated ?? false)) {
                $connection->close();
                return;
            }

            $payload = json_decode((string) $message, true);
            if (!is_array($payload)) {
                return;
            }

            if (($payload['type'] ?? null) === 'ping') {
                $connection->send(json_encode([
                    'type' => 'pong',
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        };

        $server->onClose = function ($connection) use (&$connections) {
            unset($connections[$connection->id]);
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
        if ($serverIds === [] && $groupIds === []) {
            return true;
        }

        $serverId = (int) ($connection->xboard_server_id ?? 0);
        if ($serverIds !== [] && in_array($serverId, $serverIds, true)) {
            return true;
        }

        $connectionGroupIds = $this->normalizeIntList((array) ($connection->xboard_group_ids ?? []));
        if ($groupIds !== [] && array_intersect($groupIds, $connectionGroupIds) !== []) {
            return true;
        }

        return false;
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
}
