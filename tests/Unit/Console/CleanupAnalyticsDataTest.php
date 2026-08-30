<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\CleanupAnalyticsData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class CleanupAnalyticsDataTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        config()->set('analytics.retention.visitor_days', 35);
        config()->set('analytics.retention.invite_click_days', 180);
        config()->set('analytics.retention.domain_metric_days', 400);
        config()->set('analytics.cleanup_batch_size', 100);

        Schema::create('v2_domain_visitor_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('record_date', 10);
        });
        Schema::create('v2_domain_metric_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('record_date', 10);
        });
        Schema::create('v2_invite_click', function (Blueprint $table): void {
            $table->id();
            $table->integer('last_clicked_at');
        });
    }

    public function test_cleanup_deletes_only_rows_older_than_each_retention_window(): void
    {
        DB::table('v2_domain_visitor_daily')->insert([
            ['id' => 1, 'record_date' => now()->subDays(36)->format('Y-m-d')],
            ['id' => 2, 'record_date' => now()->subDays(34)->format('Y-m-d')],
        ]);
        DB::table('v2_invite_click')->insert([
            ['id' => 1, 'last_clicked_at' => time() - (181 * 86400)],
            ['id' => 2, 'last_clicked_at' => time() - (179 * 86400)],
        ]);
        DB::table('v2_domain_metric_daily')->insert([
            ['id' => 1, 'record_date' => now()->subDays(401)->format('Y-m-d')],
            ['id' => 2, 'record_date' => now()->subDays(399)->format('Y-m-d')],
        ]);

        $result = app(CleanupAnalyticsData::class)->handle();

        $this->assertSame(0, $result);
        $this->assertSame([2], DB::table('v2_domain_visitor_daily')->pluck('id')->all());
        $this->assertSame([2], DB::table('v2_invite_click')->pluck('id')->all());
        $this->assertSame([2], DB::table('v2_domain_metric_daily')->pluck('id')->all());
    }
}
