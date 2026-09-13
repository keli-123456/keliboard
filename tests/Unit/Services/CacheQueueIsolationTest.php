<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CacheQueueIsolation;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Console\ClearCommand;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class CacheQueueIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'],
            'cache.stores.redis-cache' => ['driver' => 'redis', 'connection' => 'cache'],
            'database.redis.default' => ['host' => 'queue', 'database' => 0],
            'database.redis.cache' => ['host' => 'cache', 'database' => 1],
            'horizon.use' => 'default',
            'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default'],
        ]);
    }

    public function test_shared_queue_database_is_blocked_without_connecting_to_redis(): void
    {
        $this->assertSame(['safe' => false, 'reason' => 'shared_queue_database'], (new CacheQueueIsolation())->inspect());
    }

    public function test_isolated_database_and_non_redis_cache_are_allowed(): void
    {
        $this->assertTrue((new CacheQueueIsolation())->inspect('redis-cache')['safe']);
        config(['cache.stores.file.driver' => 'file']);
        $this->assertTrue((new CacheQueueIsolation())->inspect('file')['safe']);
    }

    public function test_redis_url_database_overrides_the_database_field(): void
    {
        config(['cache.stores.redis.connection' => 'cache', 'database.redis.cache.url' => 'redis://alias:6379/0']);
        $this->bindServerIds(['default' => 'same', 'cache' => 'same']);
        $this->assertFalse((new CacheQueueIsolation())->inspect()['safe']);
    }

    public function test_aliases_with_different_hosts_or_prefixes_do_not_bypass_protection(): void
    {
        config(['cache.stores.redis.connection' => 'cache', 'database.redis.cache.database' => 0,
            'database.redis.cache.prefix' => 'only-cache:']);
        $this->bindServerIds(['default' => 'same', 'cache' => 'same']);
        $this->assertSame('shared_queue_database', (new CacheQueueIsolation())->inspect()['reason']);
        $this->bindServerIds(['default' => 'queue-server', 'cache' => 'cache-server']);
        $this->assertTrue((new CacheQueueIsolation())->inspect()['safe']);
    }

    public function test_unverifiable_identity_and_unknown_configuration_fail_closed(): void
    {
        config(['cache.stores.redis.connection' => 'cache', 'database.redis.cache.database' => 0]);
        $this->bindServerIds(['default' => '', 'cache' => '']);
        $this->assertSame('unverified_redis_database', (new CacheQueueIsolation())->inspect()['reason']);
        config(['cache.stores.redis.connection' => 'missing']);
        $this->assertFalse((new CacheQueueIsolation())->inspect()['safe']);
    }

    public function test_other_queue_connections_and_sessions_are_protected(): void
    {
        config(['queue.connections.other' => ['driver' => 'redis', 'connection' => 'cache']]);
        $this->assertFalse((new CacheQueueIsolation())->inspect('redis-cache')['safe']);
        config(['queue.connections.other' => [], 'session.driver' => 'redis', 'session.connection' => 'cache']);
        $this->assertFalse((new CacheQueueIsolation())->inspect('redis-cache')['safe']);
    }

    public function test_artisan_cache_clear_is_intercepted_before_any_flush(): void
    {
        (new \App\Providers\EventServiceProvider(app()))->boot();
        $manager = $this->createMock(CacheManager::class);
        $manager->expects($this->never())->method('store');
        $command = new ClearCommand($manager, new Filesystem());
        $command->setLaravel(app());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache clear refused: shared_queue_database');
        $command->run(new ArrayInput([]), new BufferedOutput());
    }

    private function bindServerIds(array $ids): void
    {
        app()->instance('redis', new class($ids) {
            public function __construct(private array $ids) {}
            public function connection(string $name): object {
                return new class($this->ids[$name]) {
                    public function __construct(private string $id) {}
                    public function info(string $section): array { return ['run_id' => $this->id]; }
                };
            }
        });
    }
}
