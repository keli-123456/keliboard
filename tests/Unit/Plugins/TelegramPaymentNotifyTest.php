<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteOrderContext;
use App\Services\TelegramService;
use Plugin\Telegram\Plugin as TelegramPlugin;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TelegramPaymentNotifyTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private CapturingTelegramService $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();

        $this->telegram = new CapturingTelegramService();
    }

    public function test_payment_notify_includes_site_source_for_site_order(): void
    {
        $site = $this->createSite('gm', '光喵');
        $domain = $this->createSiteDomain($site, 'gm.example.test');
        $order = $this->createPaidOrder('site-order-trade', 1300, $site->id);

        SiteOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'site_id' => $site->id,
            'site_domain_id' => $domain->id,
            'sale_amount' => 1300,
            'platform_plan_price' => 1300,
        ]);

        $this->plugin()->sendPaymentNotify($order);

        $this->assertCount(1, $this->telegram->messages);
        $this->assertStringContainsString('站点来源：光喵（gm.example.test）', $this->telegram->messages[0]);
    }

    public function test_payment_notify_includes_agent_domain_source_for_agent_order(): void
    {
        $agentDomain = AgentDomain::query()->create([
            'agent_user_id' => 1001,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
        ]);
        $order = $this->createPaidOrder('agent-order-trade', 1500, null);

        AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => 1001,
            'agent_domain_id' => $agentDomain->id,
            'sale_amount' => 1500,
            'cost_amount' => 1000,
            'status' => AgentOrderContext::STATUS_PAID,
            'domain_snapshot' => [
                'source' => 'domain',
                'domain' => 'agent.example.test',
            ],
        ]);

        $this->plugin()->sendPaymentNotify($order);

        $this->assertCount(1, $this->telegram->messages);
        $this->assertStringContainsString('站点来源：代理域名（agent.example.test）', $this->telegram->messages[0]);
    }

    public function test_payment_notify_uses_platform_source_when_order_has_no_site_context(): void
    {
        $order = $this->createPaidOrder('platform-order-trade', 990, null);

        $this->plugin()->sendPaymentNotify($order);

        $this->assertCount(1, $this->telegram->messages);
        $this->assertStringContainsString('站点来源：主站', $this->telegram->messages[0]);
    }

    private function plugin(): TelegramPlugin
    {
        $plugin = new TelegramPlugin('Telegram');
        $plugin->setConfig(['enable_payment_notify' => true]);

        $property = new \ReflectionProperty(TelegramPlugin::class, 'telegramService');
        $property->setAccessible(true);
        $property->setValue($plugin, $this->telegram);

        return $plugin;
    }

    private function createPayment(): Payment
    {
        return Payment::query()->create([
            'uuid' => 'payment-uuid',
            'payment' => 'alipay',
            'name' => '支付宝当面付',
            'enable' => true,
        ]);
    }

    private function createPaidOrder(string $tradeNo, int $amount, ?int $siteId): Order
    {
        $payment = $this->createPayment();

        return Order::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'plan_id' => 1,
            'payment_id' => $payment->id,
            'period' => 'month_price',
            'trade_no' => $tradeNo,
            'total_amount' => $amount,
            'status' => Order::STATUS_COMPLETED,
        ]);
    }

    private function createSite(string $code, string $name): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
        ]);
    }

    private function createSiteDomain(Site $site, string $domain): SiteDomain
    {
        return SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => Site::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
    }
}

final class CapturingTelegramService extends TelegramService
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct()
    {
    }

    public function sendMessageWithAdmin(string $message, bool $isStaff = false): void
    {
        $this->messages[] = $message;
    }
}
