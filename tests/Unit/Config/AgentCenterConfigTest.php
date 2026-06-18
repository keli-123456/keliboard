<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCenterConfigTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindJsonResponseFactory();
        $this->bindTestSettings();
    }

    public function test_config_save_accepts_agent_center_settings(): void
    {
        foreach ([
            'agent_center_enable',
            'agent_center_unlock_mode',
            'agent_center_unlock_balance',
            'agent_center_auto_activate',
            'agent_center_allowed_plan_ids',
            'agent_center_discount_percent',
            'agent_center_user_limit',
            'agent_center_daily_create_limit',
            'agent_center_allow_traffic_reset',
            'agent_center_reset_price_mode',
            'agent_center_bonus_day_price',
            'agent_center_domain_limit',
        ] as $key) {
            $this->assertArrayHasKey($key, ConfigSave::RULES);
        }

        $this->assertSame('integer|min:0|max:1000', ConfigSave::RULES['agent_center_domain_limit']);
    }

    public function test_fetch_agent_config_includes_domain_limit_default_and_clamps_to_non_negative(): void
    {
        $payload = $this->responsePayload(app(ConfigController::class)->fetch(
            Request::create('/admin/config/fetch', 'GET', ['key' => 'agent'])
        ));

        $this->assertSame(1, $payload['data']['agent']['agent_center_domain_limit']);

        $this->bindTestSettings(['agent_center_domain_limit' => -5]);
        $payload = $this->responsePayload(app(ConfigController::class)->fetch(
            Request::create('/admin/config/fetch', 'GET', ['key' => 'agent'])
        ));

        $this->assertSame(0, $payload['data']['agent']['agent_center_domain_limit']);
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
