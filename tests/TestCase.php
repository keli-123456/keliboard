<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationServiceProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        // Keep compatibility with facades/helpers expecting "app" binding.
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // Minimal config repository for config() helper calls.
        $app->instance('config', new ConfigRepository([
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $app->storagePath('app'),
                    ],
                ],
            ],
        ]));

        // Register only the filesystem bindings needed by Storage::fake().
        (new FilesystemServiceProvider($app))->register();

        // Minimal translator used by validation and __()/trans() helpers.
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));

        (new ValidationServiceProvider($app))->register();
        (new FoundationServiceProvider($app))->registerRequestValidation();

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
