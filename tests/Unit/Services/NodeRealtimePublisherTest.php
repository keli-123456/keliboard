<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Node\NodeUserService;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class NodeRealtimePublisherTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_build_targets_includes_machine_ids_without_breaking_existing_targets(): void
    {
        $method = new ReflectionMethod(NodeRealtimePublisher::class, 'buildTargets');
        $method->setAccessible(true);

        $targets = $method->invoke(new NodeRealtimePublisher(new NodeRealtimeSettings()), [3, '2', 2], [9], [7, 'bad', 7]);

        $this->assertSame([
            'server_ids' => [2, 3],
            'group_ids' => [9],
            'machine_ids' => [7],
        ], $targets);
    }

    public function test_build_targets_returns_null_for_empty_target_lists(): void
    {
        $method = new ReflectionMethod(NodeRealtimePublisher::class, 'buildTargets');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(new NodeRealtimePublisher(new NodeRealtimeSettings())));
    }

    public function test_invalidate_users_for_servers_clears_shared_and_legacy_user_cache_keys(): void
    {
        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type')->default('vless');
            $table->json('group_ids')->nullable();
            $table->integer('updated_at')->nullable();
        });

        DB::table('v2_server')->insert([
            'id' => 101,
            'type' => 'vless',
            'group_ids' => json_encode([10]),
            'updated_at' => time(),
        ]);

        $nodeUserService = new NodeUserService();
        $server = Server::query()->findOrFail(101);
        $sharedKey = $nodeUserService->userCacheKey($server);
        $legacyKey = 'server_api:user:101';

        Cache::store()->put($sharedKey, ['etag' => 'shared', 'body' => '{}'], 60);
        Cache::store()->put($legacyKey, ['etag' => 'legacy', 'body' => '{}'], 60);

        (new NodeRealtimePublisher(new NodeRealtimeSettings()))->invalidateUsersForServers([101]);

        $this->assertFalse(Cache::store()->has($sharedKey));
        $this->assertFalse(Cache::store()->has($legacyKey));
    }
}
