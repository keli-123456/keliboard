<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteSetting;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_site_setting_belongs_to_site(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $setting = SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Cheap Cloud',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'landing_theme' => 'sakura',
            'accent_color' => '#f43f5e',
            'support_name' => 'Cheap Support',
            'support_url' => 'https://t.me/support',
            'announcement' => 'Welcome',
            'seo_title' => 'Cheap Cloud',
            'seo_description' => 'Fast access',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame($site->id, (int) $setting->site->id);
        $this->assertSame('Cheap Cloud', $site->fresh(['setting'])->setting->site_name);
    }
}
