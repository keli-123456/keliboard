<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\SiteNavigationController;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Services\SiteNavigationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminSiteNavigationControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestSettings([
            'app_name' => 'Keli',
            'app_url' => 'https://main.example.test',
            'website_proxy_enable' => false,
        ]);
        $this->createTables();
    }

    public function test_admin_can_save_and_fetch_platform_navigation(): void
    {
        $controller = app(SiteNavigationController::class);
        $payload = $this->responsePayload($controller->save(
            Request::create('/admin/site/navigation/save', 'POST', [
                'site_id' => null,
                'enabled' => true,
                'title' => 'Keli 地址',
                'description' => '请选择入口',
                'announcement' => '建议收藏本页',
                'domains' => [
                    [
                        'domain' => 'https://Nav.Example.Test/path',
                        'status' => 'active',
                        'is_primary' => true,
                    ],
                    [
                        'domain' => 'nav2.example.test',
                        'status' => 'active',
                        'is_primary' => false,
                    ],
                ],
                'links' => [
                    [
                        'label' => '备用客服',
                        'url' => 'https://support.example.test/help',
                        'enabled' => true,
                    ],
                ],
            ]),
            app(SiteNavigationService::class)
        ));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('platform', $payload['data']['scope_key']);
        $this->assertSame('nav.example.test', $payload['data']['domains'][0]['domain']);
        $this->assertSame('https://nav.example.test', $payload['data']['preview_url']);
        $this->assertSame([
            'https://main.example.test',
            'https://support.example.test/help',
        ], array_column($payload['data']['destinations'], 'url'));

        $fetched = $this->responsePayload($controller->fetch(app(SiteNavigationService::class)));
        $this->assertSame('success', $fetched['status']);
        $this->assertSame('platform', $fetched['data'][0]['scope_key']);
        $this->assertTrue($fetched['data'][0]['enabled']);
    }

    public function test_enabled_navigation_requires_an_active_domain(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('At least one active navigation domain is required');

        app(SiteNavigationController::class)->save(
            Request::create('/admin/site/navigation/save', 'POST', [
                'enabled' => true,
                'domains' => [],
                'links' => [],
            ]),
            app(SiteNavigationService::class)
        );
    }

    public function test_rejects_storefront_domain_and_non_https_manual_link(): void
    {
        $site = Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'shop.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);

        try {
            app(SiteNavigationController::class)->save(
                Request::create('/admin/site/navigation/save', 'POST', [
                    'enabled' => true,
                    'domains' => [[
                        'domain' => 'shop.example.test',
                        'status' => 'active',
                        'is_primary' => true,
                    ]],
                    'links' => [],
                ]),
                app(SiteNavigationService::class)
            );
            $this->fail('Storefront domain collision was accepted.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain already assigned', $exception->getMessage());
        }

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Navigation links must use a valid HTTPS URL');
        app(SiteNavigationController::class)->save(
            Request::create('/admin/site/navigation/save', 'POST', [
                'enabled' => false,
                'domains' => [],
                'links' => [[
                    'label' => 'Unsafe',
                    'url' => 'http://backup.example.test',
                    'enabled' => true,
                ]],
            ]),
            app(SiteNavigationService::class)
        );
    }

    private function createTables(): void
    {
        Schema::create('v2_site', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('status');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_site_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('domain')->unique();
            $table->string('status');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_site_setting', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique();
            $table->string('site_name')->nullable();
            $table->string('logo_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('v2_site_navigation', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key')->unique();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->string('announcement')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_site_navigation_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('navigation_id');
            $table->string('domain')->unique();
            $table->string('status');
            $table->boolean('is_primary')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('v2_site_navigation_link', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('navigation_id');
            $table->string('label');
            $table->string('url');
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    private function bindRequestValidateMacro(): void
    {
        if (Request::hasMacro('validate')) {
            return;
        }

        Request::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function responsePayload(mixed $response): array
    {
        return $response->getData(true);
    }
}
