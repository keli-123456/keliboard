<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('xboard:statistics')->dailyAt('0:10')->onOneServer();
        // check
        $schedule->command('check:order')->everyMinute()->onOneServer();
        $schedule->command('check:commission')->everyMinute()->onOneServer();
        $schedule->command('check:ticket')->everyMinute()->onOneServer();
        $schedule->command('renew:auto')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('message-dispatch:release', ['--limit' => 200])->everyMinute()->onOneServer()->withoutOverlapping(1);
        $schedule->command('message-dispatch:recover-stuck', ['--limit' => 200])->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);
        // reset
        $schedule->command('reset:traffic')->everyMinute()->onOneServer();
        $schedule->command('reset:log')->daily()->onOneServer();
        $schedule->command('check:node-realtime-alert')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);
        // user sync (users_revision)
        $schedule->command('usersync:reconcile')->everyMinute()->onOneServer()->withoutOverlapping(2);
        $schedule->command('usersync:cleanup')->dailyAt('3:10')->onOneServer();
        $schedule->command('marketing:scan')->everyTenMinutes()->onOneServer()->withoutOverlapping(8);
        $schedule->command('spam-registration:scan')->hourly()->onOneServer()->withoutOverlapping(50);
        // send
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer();
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // backup Timing
        // if (env('ENABLE_AUTO_BACKUP_AND_UPDATE', false)) {
        //     $schedule->command('backup:database', ['true'])->daily()->onOneServer();
        // }
        $schedule->command('cleanup:expired-online-status')->everyMinute()->onOneServer()->withoutOverlapping(4);
        $schedule->command('cleanup:ticket')->dailyAt('3:20')->onOneServer()->withoutOverlapping();
        if (config('tickets.attachments.prewarm_schedule', false)) {
            $schedule->command('ticket:prewarm-thumbnails', [
                '--chunk' => max(50, (int) config('tickets.attachments.prewarm_schedule_chunk', 200)),
                '--limit' => max(0, (int) config('tickets.attachments.prewarm_schedule_limit', 500)),
            ])->dailyAt('3:35')->onOneServer()->withoutOverlapping();
        }

        app(PluginManager::class)->registerPluginSchedules($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Exception $e) {
        }
        require base_path('routes/console.php');
    }
}
