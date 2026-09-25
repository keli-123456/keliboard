<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\UserSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!isset($root, $fixture) || getenv('KELI_TRAFFIC_NODE_FIXTURE') !== '1'
    || $fixture->resetFixture === null || $fixture->redisFixture === null
    || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 65534) {
    throw new RuntimeException('Isolated non-root node fixture only.');
}

return new class($root) {
    public function __construct(private string $root) {}

    public function boot(): void
    {
        // Restore the shipped publisher instead of the reset fixture's capture double.
        app()->forgetInstance(NodeRealtimePublisher::class);
        config(['node_realtime.enabled' => false, 'user_sync.use_state_table_for_server_users' => true]);
        app(\App\Support\Setting::class)->save([
            'server_push_interval' => 1, 'server_pull_interval' => 5,
            'server_api_user_cache_ttl' => 120, 'server_api_config_cache_ttl' => 120,
            'node_realtime_enable' => false,
        ]);
        if (get_class(app(NodeRealtimePublisher::class)) !== NodeRealtimePublisher::class) {
            throw new RuntimeException('Real publisher required.');
        }
        config(['horizon.environments.acceptance.traffic.queue' => ['traffic_fetch', 'online_sync']]);
    }

    public function allows(\Illuminate\Http\Request $request): bool
    {
        return in_array($request->method() . ' ' . $request->path(), [
            'GET api/v2/server/config', 'GET api/v2/server/machine/nodes',
            'POST api/v2/server/machine/status', 'POST api/v2/server/report',
            'GET api/v1/server/UniProxy/user', 'GET api/v1/server/UniProxy/user_delta',
            'GET api/v1/server/UniProxy/alivelist', 'POST api/v1/server/UniProxy/alive',
        ], true);
    }

    public function record($request, $response): void
    {
        file_put_contents($this->root . '/node-http.jsonl', json_encode([
            'at' => microtime(true), 'method' => $request->method(), 'path' => $request->path(),
            'input' => $request->except('token'), 'status' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true),
        ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function action(string $action, string $argument): void
    {
        if ($action === 'node-restore') {
            $user = User::query()->findOrFail(7);
            if ((int) $user->transfer_enable !== 16000 || (int) $user->u + (int) $user->d < 16000) {
                throw new RuntimeException('Expected actual exhausted quota.');
            }
            $user->transfer_enable = 1000000;
            $user->save();
            if (!DB::table('user_sync_states')->where('user_id', 7)->value('available')) {
                throw new RuntimeException('Real observer did not restore availability.');
            }
            return;
        }
        $data = json_decode($argument, true, 16, JSON_THROW_ON_ERROR);
        $directory = realpath((string) ($data['directory'] ?? ''));
        $evidence = realpath((string) getenv('KELI_TRAFFIC_EVIDENCE_DIR'));
        if ($directory === false || $evidence === false || dirname($directory) !== dirname($evidence)
            || !in_array(basename($directory), ['embedded-hy2', 'native-hy2'], true)
            || !is_file($directory . '/server.crt') || !is_file($directory . '/server.key')
            || !is_int($data['port'] ?? null) || $data['port'] < 1024 || $data['port'] > 65535
            || DB::table('v2_traffic_batch')->exists() || Schema::hasColumn('v2_server', 'group_ids')) {
            throw new RuntimeException('Expected pristine isolated control-plane fixture.');
        }
        Schema::table('v2_server', function (Blueprint $table): void {
            $table->json('group_ids')->nullable();
            $table->json('route_ids')->nullable();
            $table->json('protocol_settings')->nullable();
            $table->integer('parent_id')->nullable();
            $table->integer('sort')->default(0);
            $table->integer('server_port')->nullable();
            $table->timestamps();
        });
        Schema::table('v2_server_machine', function (Blueprint $table): void {
            $table->integer('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->json('upgrade_state')->nullable();
            $table->boolean('subproxy_enabled')->default(false);
            $table->boolean('webproxy_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('machine_id');
            $table->double('cpu');
            foreach (['mem_total', 'mem_used', 'swap_total', 'swap_used', 'disk_total', 'disk_used'] as $field) {
                $table->bigInteger($field);
            }
            $table->json('load_status');
            $table->timestamps();
        });
        Schema::table('v2_user', function (Blueprint $table): void {
            $table->integer('online_count')->default(0);
            $table->timestamp('last_online_at')->nullable();
        });
        DB::table('v2_server')->where('id', 1)->update([
            'type' => 'hysteria', 'group_ids' => '["10"]', 'route_ids' => '[]',
            'server_port' => $data['port'], 'created_at' => now(), 'updated_at' => now(),
            'protocol_settings' => json_encode([
                'version' => 2, 'network' => 'hysteria', 'bandwidth' => ['up' => 0, 'down' => 0],
                'obfs' => ['open' => false, 'type' => 'salamander', 'password' => ''],
                'tls' => ['server_name' => 'localhost'],
                'tls_settings' => ['cert_mode' => 'file', 'server_name' => 'localhost',
                    'cert_file' => $directory . '/server.crt', 'key_file' => $directory . '/server.key'],
            ], JSON_THROW_ON_ERROR),
        ]);
        // Seed entities only; HTTP responses come from the shipped services.
        DB::table('v2_user')->where('id', 7)->update([
            'uuid' => 'uuid-a', 'transfer_enable' => 16000, 'next_reset_at' => time() + 86400,
        ]);
        app(UserSyncService::class)->syncUser(User::query()->findOrFail(7), 'updated');
        DB::table('v2_user')->insert([
            'id' => 8, 'group_id' => 99, 'uuid' => 'different-group', 'transfer_enable' => 1000000,
            'next_reset_at' => time() + 86400,
        ]);
        app(UserSyncService::class)->syncUser(User::query()->findOrFail(8), 'created');
        if (DB::table('user_sync_events')->count() !== 2) {
            throw new RuntimeException('Expected committed baseline events.');
        }
    }
};
