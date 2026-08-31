<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Server\ServerController;
use App\Jobs\UpdateAliveDataJob;
use App\Services\UserOnlineService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerOnlineReportTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindSynchronousBusDispatcher();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings([
            'device_limit_mode' => 0,
            'server_push_interval' => 60,
        ]);
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('online_count')->default(0);
            $table->timestamp('last_online_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function test_empty_online_snapshot_resets_node_online_user_count(): void
    {
        $controller = new ServerController();
        $cacheKey = CacheKey::get('SERVER_TROJAN_ONLINE_USER', 53);

        $controller->report($this->request([
            'online' => [
                '1' => 2,
                '2' => 1,
            ],
        ]));
        $this->assertSame(2, Cache::get($cacheKey));

        $controller->report($this->request(['online' => []]));

        $this->assertSame(0, Cache::get($cacheKey));
    }

    public function test_empty_alive_snapshot_is_dispatched_and_removes_previous_node_users(): void
    {
        DB::table('v2_user')->insert(['id' => 1, 'online_count' => 0]);
        (new UpdateAliveDataJob([
            1 => ['203.0.113.10'],
        ], 'trojan', 53))->handle();
        $this->assertSame(1, UserOnlineService::getUserDevices(1)['total_count']);

        (new ServerController())->report($this->request([
            'alive' => [],
            'online' => [],
        ]));

        $this->assertSame(0, UserOnlineService::getUserDevices(1)['total_count']);
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('online_count'));
    }

    private function request(array $payload): Request
    {
        $request = Request::create(
            '/api/v2/server/report',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->attributes->set('node_info', (object) [
            'id' => 53,
            'parent_id' => null,
            'type' => 'trojan',
        ]);

        return $request;
    }
}
