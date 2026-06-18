<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentPaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentPaymentServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->bindTestSettings(['agent_center_enable' => 1]);
        HookManager::reset();
        $this->bindEmptyPaymentGateway();
    }

    public function test_agent_can_create_payment_for_enabled_plugin(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');

        $payment = app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'FAKEPAY',
            'config' => ['merchant_id' => 'agent-merchant'],
            'enable' => true,
        ]);

        $this->assertSame(Payment::OWNER_AGENT, $payment->owner_type);
        $this->assertSame($agent->id, (int) $payment->owner_id);
        $this->assertSame('FAKEPAY', $payment->payment);
        $this->assertSame('agent-merchant', $payment->config['merchant_id']);
        $this->assertTrue((bool) $payment->enable);
    }

    public function test_agent_cannot_edit_another_agent_payment(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $payment = Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $otherAgent->id,
            'uuid' => 'other001',
            'payment' => 'FAKEPAY',
            'name' => 'Other Agent',
            'config' => ['merchant_id' => 'other'],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment method is unavailable');

        app(AgentPaymentService::class)->save($agent, [
            'id' => $payment->id,
            'name' => 'Hijack',
            'payment' => 'FAKEPAY',
            'config' => ['merchant_id' => 'agent'],
        ]);
    }

    public function test_agent_payment_requires_enabled_platform_plugin(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment plugin is not enabled');

        app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'MISSING',
            'config' => ['merchant_id' => 'agent-merchant'],
        ]);
    }

    public function test_agent_payment_notify_url_uses_payment_uuid(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $payment = app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'FAKEPAY',
            'config' => ['merchant_id' => 'agent-merchant'],
        ]);

        $rows = app(AgentPaymentService::class)->list($agent);

        $this->assertSame(
            "https://panel.example.test/api/v1/guest/payment/notify/FAKEPAY/{$payment->uuid}",
            $rows[0]['notify_url']
        );
    }

    public function test_agent_payment_edit_preserves_existing_config_keys_when_missing(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $payment = Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'uuid' => 'agent001',
            'payment' => 'FAKEPAY',
            'name' => 'Agent USDT',
            'config' => ['merchant_id' => 'old-merchant', 'secret' => 'keep-secret'],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $updated = app(AgentPaymentService::class)->save($agent, [
            'id' => $payment->id,
            'name' => 'Agent USDT Updated',
            'payment' => 'FAKEPAY',
            'config' => ['merchant_id' => 'new-merchant'],
        ]);

        $this->assertSame('new-merchant', $updated->config['merchant_id']);
        $this->assertSame('keep-secret', $updated->config['secret']);
    }

    public function test_agent_payment_rejects_pending_domain_for_current_agent(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'pending.example.test', AgentDomain::STATUS_PENDING);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is unavailable');

        app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'FAKEPAY',
            'owner_domain_id' => $domain->id,
            'config' => ['merchant_id' => 'agent-merchant'],
        ]);
    }

    public function test_agent_payment_rejects_another_agents_active_domain(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $domain = $this->createDomain($otherAgent, 'other.example.test', AgentDomain::STATUS_ACTIVE);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is unavailable');

        app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'FAKEPAY',
            'owner_domain_id' => $domain->id,
            'config' => ['merchant_id' => 'agent-merchant'],
        ]);
    }

    public function test_agent_payment_accepts_current_agent_active_domain(): void
    {
        $this->bindFakePaymentGateway();
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'active.example.test', AgentDomain::STATUS_ACTIVE);

        $payment = app(AgentPaymentService::class)->save($agent, [
            'name' => 'Agent USDT',
            'payment' => 'FAKEPAY',
            'owner_domain_id' => $domain->id,
            'config' => ['merchant_id' => 'agent-merchant'],
        ]);

        $this->assertSame($domain->id, (int) $payment->owner_domain_id);
    }

    public function test_toggle_rejects_deleted_bound_domain_when_enabling_payment(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'deleted.example.test', AgentDomain::STATUS_ACTIVE);
        $payment = $this->createPayment($agent, $domain, false);
        $domain->delete();

        try {
            app(AgentPaymentService::class)->toggle($agent, $payment->id);
            $this->fail('Expected unavailable domain exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain is unavailable', $exception->getMessage());
        }

        $this->assertFalse((bool) $payment->fresh()->enable);
    }

    public function test_toggle_rejects_disabled_bound_domain_when_enabling_payment(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'disabled.example.test', AgentDomain::STATUS_ACTIVE);
        $payment = $this->createPayment($agent, $domain, false);
        $domain->status = AgentDomain::STATUS_DISABLED;
        $domain->save();

        try {
            app(AgentPaymentService::class)->toggle($agent, $payment->id);
            $this->fail('Expected unavailable domain exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain is unavailable', $exception->getMessage());
        }

        $this->assertFalse((bool) $payment->fresh()->enable);
    }

    private function bindFakePaymentGateway(): void
    {
        HookManager::registerFilter('available_payment_methods', static function (array $methods): array {
            $methods['FAKEPAY'] = [
                'name' => 'Fake Pay',
                'icon' => 'fake',
                'plugin_code' => 'fakepay',
                'type' => 'plugin',
            ];

            return $methods;
        });

        app()->instance(PluginManager::class, new class {
            public function initializeEnabledPlugins(): void {}

            public function getEnabledPaymentPlugins(): array
            {
                return [new class {
                    private array $config = [];

                    public function getPluginCode(): string
                    {
                        return 'fakepay';
                    }

                    public function setConfig(array $config): void
                    {
                        $this->config = $config;
                    }

                    public function form(): array
                    {
                        return [
                            'merchant_id' => [
                                'type' => 'string',
                                'label' => 'Merchant ID',
                                'default' => $this->config['merchant_id'] ?? '',
                            ],
                        ];
                    }
                }];
            }
        });
    }

    private function bindEmptyPaymentGateway(): void
    {
        app()->instance(PluginManager::class, new class {
            public function initializeEnabledPlugins(): void {}

            public function getEnabledPaymentPlugins(): array
            {
                return [];
            }
        });
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 10000,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createDomain(User $agent, string $domain, string $status): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => $status,
            'is_primary' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPayment(User $agent, AgentDomain $domain, bool $enable): Payment
    {
        return Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => substr(md5($agent->email . ':' . $domain->domain), 0, 8),
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay',
            'config' => [],
            'enable' => $enable,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
