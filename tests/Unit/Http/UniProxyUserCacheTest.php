<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\Server;
use App\Services\Node\NodeConfigService;
use App\Services\Node\NodeUserService;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserOnlineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UniProxyUserCacheTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->resetServerServiceUserCache();

        config([
            'server_api_cache.user_ttl' => 60,
            'server_api_cache.lock_ttl' => 1,
            'server_api_cache.lock_wait' => 0,
            'server_api_cache.store' => null,
            'user_sync.use_state_table_for_server_users' => false,
        ]);
    }

    public function test_user_cache_is_shared_for_nodes_with_same_user_scope(): void
    {
        $controller = $this->controller();
        $nodeA = $this->node(101, 'vless', [10]);
        $nodeB = $this->node(102, 'trojan', [10]);

        $this->insertUser(1, 10, 'uuid-1');
        $first = $this->userPayload($controller, $nodeA);

        $this->insertUser(2, 10, 'uuid-2');
        $second = $this->userPayload($controller, $nodeB);

        $this->assertSame(['uuid-1'], array_column($first['users'], 'uuid'));
        $this->assertSame(['uuid-1'], array_column($second['users'], 'uuid'));
    }

    public function test_user_cache_stays_node_scoped_when_server_user_hook_exists(): void
    {
        HookManager::registerFilter('server.users.get', function ($users, $node) {
            return $users->filter(fn ($user) => (int) $user->id === (int) $node->id)->values();
        });

        $controller = $this->controller();
        $nodeA = $this->node(1, 'vless', [10]);
        $nodeB = $this->node(2, 'trojan', [10]);

        $this->insertUser(1, 10, 'uuid-1');
        $this->insertUser(2, 10, 'uuid-2');

        $first = $this->userPayload($controller, $nodeA);
        $second = $this->userPayload($controller, $nodeB);

        $this->assertSame(['uuid-1'], array_column($first['users'], 'uuid'));
        $this->assertSame(['uuid-2'], array_column($second['users'], 'uuid'));
    }

    private function controller(): UniProxyController
    {
        return new UniProxyController(
            new UserOnlineService(),
            new NodeConfigService(),
            new NodeUserService()
        );
    }

    private function userPayload(UniProxyController $controller, Server $node): array
    {
        $request = Request::create('/api/v1/server/UniProxy/user', 'GET');
        $request->attributes->set('node_info', $node);

        $payload = json_decode((string) $controller->user($request)->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function node(int $id, string $type, array $groupIds): Server
    {
        $node = new Server();
        $node->id = $id;
        $node->parent_id = 0;
        $node->type = $type;
        $node->group_ids = $groupIds;

        return $node;
    }

    private function insertUser(int $id, int $groupId, string $uuid): void
    {
        DB::table('v2_user')->insert([
            'id' => $id,
            'email' => "user-{$id}@example.test",
            'password' => 'secret',
            'token' => "token-{$id}",
            'uuid' => $uuid,
            'group_id' => $groupId,
            'plan_id' => 20,
            'transfer_enable' => 1024 * 1024,
            'speed_limit' => 0,
            'device_limit' => 0,
            'expired_at' => time() + 3600,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
    }

    private function resetServerServiceUserCache(): void
    {
        foreach ([
            'hasUserSyncStatesTable' => null,
            'hasServerEnabledColumn' => null,
            'userSyncStatesReadDisabled' => false,
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(ServerService::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $value);
        }
    }
}
