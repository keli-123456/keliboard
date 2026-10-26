<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteNavigationRecommendationMigrationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_existing_settings_are_preserved_and_repeated_migration_and_rollback_are_safe(): void
    {
        $this->setUpInMemoryDatabase();
        Schema::create('v2_site_navigation', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        DB::table('v2_site_navigation')->insert(['title' => 'Original']);
        $migration = require database_path('migrations/2026_10_26_000001_add_recommended_entry_to_site_navigation.php');
        $migration->up();
        $migration->up();
        $row = DB::table('v2_site_navigation')->first();
        $this->assertSame('Original', $row->title);
        $this->assertNull($row->recommended_label);
        $this->assertNull($row->recommended_url);
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_site_navigation', 'recommended_url'));
        $this->assertSame('Original', DB::table('v2_site_navigation')->value('title'));
    }
}
