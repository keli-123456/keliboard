<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\SubscriptionRiskAiSettings as Risk;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionRiskAiSettingsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase();
        Schema::create('v2_settings', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->text('value')->nullable(); $table->timestamps();
        });
    }

    public function test_model_override_preserves_connection_key_and_master_switch_but_not_ticket_generation_controls(): void
    {
        Setting::createOrUpdate('risk_ai_model', 'gpt-6-luna');
        Setting::createOrUpdate('risk_ai_reasoning_effort', 'none');
        $shared = ['enabled' => false, 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'synthetic-secret',
            'model' => 'gpt-6.1-sol', 'max_tokens' => 800, 'reasoning_effort' => 'high', 'timeout' => 60];
        $risk = Risk::forProvider($shared);
        $this->assertSame('gpt-6-luna', $risk['model']);
        $this->assertSame('none', $risk['reasoning_effort']);
        $this->assertSame(4096, $risk['max_tokens']);
        $this->assertSame(30, $risk['timeout']);
        $this->assertFalse($risk['enabled']);
        $this->assertSame($shared['base_url'], $risk['base_url']);
        $this->assertSame($shared['api_key'], $risk['api_key']);
        $this->assertSame('gpt-6.1-sol', $shared['model']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode(Risk::publicSettings()));
    }

    public function test_blank_model_follows_ticket_but_sol_never_receives_none_and_timeout_fits_worker(): void
    {
        Setting::createOrUpdate('risk_ai_model', '  ');
        Setting::createOrUpdate('risk_ai_reasoning_effort', 'none');
        Setting::createOrUpdate('risk_ai_timeout', 120);
        Setting::createOrUpdate('risk_ai_max_tokens', 99999);
        $risk = Risk::forProvider(['model' => 'gpt-6.1-sol']);
        $this->assertSame('gpt-6.1-sol', $risk['model']);
        $this->assertSame('low', $risk['reasoning_effort']);
        $this->assertSame(45, $risk['timeout']);
        $this->assertSame(4096, $risk['max_tokens']);
    }

    public function test_public_readiness_uses_effective_risk_model_and_never_contains_a_secret(): void
    {
        Setting::createOrUpdate('risk_ai_model', 'gpt-6-luna');
        $ticket = ['ticket_ai_enable' => true, 'ticket_ai_model' => '', 'ticket_ai_base_url' => 'https://api.openai.com/v1',
            'ticket_ai_api_key_set' => true, 'ticket_ai_api_key' => 'must-not-return'];
        $public = Risk::forPublicProvider($ticket);
        $this->assertTrue(Risk::ready($public));
        $this->assertArrayNotHasKey('api_key', $public);
        $this->assertStringNotContainsString('must-not-return', json_encode($public));
        $this->assertFalse(Risk::ready(Risk::forPublicProvider(array_merge($ticket, ['ticket_ai_enable' => false]))));
        $this->assertFalse(Risk::ready(Risk::forPublicProvider(array_merge($ticket, ['ticket_ai_api_key_set' => false]))));
    }
}
