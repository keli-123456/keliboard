<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        // Keep compatibility with facades/helpers expecting "app" binding.
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // Minimal config repository for config() helper calls.
        $app->instance('config', new ConfigRepository([]));

        // Minimal translator used by __()/trans() helpers in unit tests.
        $app->instance('translator', new class {
            public function get($key, array $replace = [], $locale = null, $fallback = true)
            {
                return (string) $key;
            }

            public function choice($key, $number, array $replace = [], $locale = null)
            {
                return (string) $key;
            }
        });

        // Minimal cache manager/repository used by Setting and Cache facade.
        $cacheStore = new CacheRepository(new ArrayStore());
        $cacheManager = new class($cacheStore) {
            public function __construct(private CacheRepository $store) {}

            public function store(?string $name = null): CacheRepository
            {
                return $this->store;
            }

            public function __call(string $method, array $arguments)
            {
                return $this->store->{$method}(...$arguments);
            }
        };
        $app->instance('cache', $cacheManager);
        $app->instance('cache.store', $cacheStore);

        $app->instance('hook.actions', []);
        $app->instance('hook.filters', []);
    }
}
