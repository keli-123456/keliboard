<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\UpdateAliveDataJob;
use App\Services\UserOnlineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UpdateAliveDataJobTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
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
        DB::table('v2_user')->insert([
            ['id' => 1, 'online_count' => 0],
            ['id' => 2, 'online_count' => 0],
        ]);
    }

    public function test_node_reports_are_authoritative_snapshots_and_display_count_stays_unique(): void
    {
        (new UpdateAliveDataJob([
            1 => ['211.158.12.8', '211.158.12.8'],
            2 => ['198.51.100.2'],
        ], 'trojan', 53))->handle();
        (new UpdateAliveDataJob([
            1 => ['::ffff:211.158.12.8'],
        ], 'vless', 54))->handle();

        $userOne = Cache::get(UserOnlineService::cacheKey(1));
        $this->assertSame(2, $userOne['alive_ip']);
        $this->assertSame(1, $userOne['online_count']);
        $this->assertSame(1, app(UserOnlineService::class)->getOnlineCount(1));
        $this->assertSame(1, (int) DB::table('v2_user')->where('id', 1)->value('online_count'));
        $this->assertSame(
            [1, 2],
            Cache::get(UserOnlineService::nodeUsersCacheKey('trojan', 53))
        );

        (new UpdateAliveDataJob([
            2 => ['198.51.100.2'],
        ], 'trojan', 53))->handle();

        $userOne = Cache::get(UserOnlineService::cacheKey(1));
        $this->assertArrayNotHasKey('trojan53', $userOne);
        $this->assertArrayHasKey('vless54', $userOne);
        $this->assertSame(1, $userOne['alive_ip']);
        $this->assertSame(1, $userOne['online_count']);

        (new UpdateAliveDataJob([], 'vless', 54))->handle();

        $userOne = Cache::get(UserOnlineService::cacheKey(1));
        $this->assertArrayNotHasKey('vless54', $userOne);
        $this->assertSame(0, $userOne['alive_ip']);
        $this->assertSame(0, $userOne['online_count']);
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('online_count'));
        $this->assertSame([], Cache::get(UserOnlineService::nodeUsersCacheKey('vless', 54)));

        $this->assertSame(1, (int) DB::table('v2_user')->where('id', 2)->value('online_count'));
    }

    public function test_older_concurrent_snapshot_cannot_restore_stale_state(): void
    {
        (new UpdateAliveDataJob([
            1 => ['203.0.113.10'],
        ], 'trojan', 53, 200.0))->handle();
        (new UpdateAliveDataJob([], 'trojan', 53, 100.0))->handle();

        $userOne = Cache::get(UserOnlineService::cacheKey(1));
        $this->assertArrayHasKey('trojan53', $userOne);
        $this->assertSame(1, $userOne['online_count']);
        $this->assertSame(
            [1],
            Cache::get(UserOnlineService::nodeUsersCacheKey('trojan', 53))
        );
    }
}
