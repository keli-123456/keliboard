<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * 事件监听器映射
     * @var array<string, array<int, class-string>>
     */
    protected $listen = [
    ];

    /**
     * 注册任何事件
     * @return void
     */
    public function boot()
    {
        parent::boot();

        $this->app['events']->listen('cache:clearing', function ($store) {
            $this->app->make(\App\Services\CacheQueueIsolation::class)->assertSafeToClear($store);
        });

        User::observe(UserObserver::class);
    }
}
