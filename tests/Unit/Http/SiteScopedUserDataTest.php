<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\NoticeController;
use App\Http\Controllers\V2\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\TicketController as AdminTicketController;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteScopedUserDataTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestUrlGenerator();
        $this->bindTestSettings([
            'ticket_auto_reply_enable' => 0,
        ]);

        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createTicketTables();
        $this->createNoticeTable();
        $this->addTicketTestColumns();
    }

    public function test_user_notices_include_global_and_matching_site_only(): void
    {
        $firstSite = $this->siteWithDomain('first', 'first.example.test', false);
        $secondSite = $this->siteWithDomain('second', 'second.example.test', false);
        $user = $this->createUser('buyer@example.test', $firstSite);

        $global = $this->createNotice('Global Notice', 'global');
        $first = $this->createNotice('First Site Notice', 'site', $firstSite->id);
        $this->createNotice('Second Site Notice', 'site', $secondSite->id);
        $this->createNotice('Platform Notice', 'platform');

        $payload = $this->responsePayload(app(NoticeController::class)->fetch($this->userRequest(
            '/api/v1/user/notice/fetch',
            $user,
            'first.example.test'
        )));

        $this->assertSame(2, $payload['total']);
        $this->assertEqualsCanonicalizing([$global->id, $first->id], array_column($payload['data'], 'id'));
        $this->assertEqualsCanonicalizing(['Global Notice', 'First Site Notice'], array_column($payload['data'], 'title'));
    }

    public function test_platform_notices_include_global_and_platform_only(): void
    {
        $site = $this->siteWithDomain('branch', 'branch.example.test', false);
        $user = $this->createUser('platform-buyer@example.test', null);

        $global = $this->createNotice('Global Notice', 'global');
        $platform = $this->createNotice('Platform Notice', 'platform');
        $this->createNotice('Branch Site Notice', 'site', $site->id);

        $payload = $this->responsePayload(app(NoticeController::class)->fetch($this->userRequest(
            '/api/v1/user/notice/fetch',
            $user,
            'main.example.test'
        )));

        $this->assertSame(2, $payload['total']);
        $this->assertEqualsCanonicalizing([$global->id, $platform->id], array_column($payload['data'], 'id'));
        $this->assertEqualsCanonicalizing(['Global Notice', 'Platform Notice'], array_column($payload['data'], 'title'));
    }

    public function test_admin_notice_fetch_can_filter_by_site_scope_and_include_site(): void
    {
        $firstSite = $this->siteWithDomain('notice-a', 'notice-a.example.test', false);
        $secondSite = $this->siteWithDomain('notice-b', 'notice-b.example.test', false);

        $global = $this->createNotice('Global Notice', 'global');
        $platform = $this->createNotice('Platform Notice', 'platform');
        $first = $this->createNotice('First Site Notice', 'site', $firstSite->id);
        $this->createNotice('Second Site Notice', 'site', $secondSite->id);

        $sitePayload = (new AdminNoticeController())->fetch(Request::create('/api/v2/admin/notice/fetch', 'GET', [
            'site_id' => $firstSite->id,
        ]))->getData(true);

        $this->assertCount(1, $sitePayload['data']);
        $this->assertSame($first->id, (int) $sitePayload['data'][0]['id']);
        $this->assertSame($firstSite->id, (int) $sitePayload['data'][0]['site_id']);
        $this->assertSame('Notice-a', $sitePayload['data'][0]['site']['name']);

        $globalPayload = (new AdminNoticeController())->fetch(Request::create('/api/v2/admin/notice/fetch', 'GET', [
            'site_id' => 'global',
        ]))->getData(true);

        $this->assertCount(1, $globalPayload['data']);
        $this->assertSame($global->id, (int) $globalPayload['data'][0]['id']);
        $this->assertSame('global', $globalPayload['data'][0]['scope_type']);
        $this->assertNull($globalPayload['data'][0]['site_id']);
        $this->assertNull($globalPayload['data'][0]['site']);

        $platformPayload = (new AdminNoticeController())->fetch(Request::create('/api/v2/admin/notice/fetch', 'GET', [
            'scope_type' => 'platform',
        ]))->getData(true);

        $this->assertCount(1, $platformPayload['data']);
        $this->assertSame($platform->id, (int) $platformPayload['data'][0]['id']);
        $this->assertSame('platform', $platformPayload['data'][0]['scope_type']);
        $this->assertNull($platformPayload['data'][0]['site_id']);
        $this->assertNull($platformPayload['data'][0]['site']);
    }

    public function test_ticket_creation_records_site_context(): void
    {
        $site = $this->siteWithDomain('support', 'support.example.test', false);
        $user = $this->createUser('ticket-buyer@example.test', $site);

        $ticket = app(TicketService::class)->createTicket(
            $user->id,
            'Need help',
            1,
            'Please check this account',
            [],
            ['site_context' => ['site_id' => $site->id]]
        );

        $this->assertSame($site->id, (int) $ticket->site_id);
    }

    public function test_admin_ticket_fetch_can_filter_by_site_id(): void
    {
        $firstSite = $this->siteWithDomain('tickets-a', 'tickets-a.example.test', false);
        $secondSite = $this->siteWithDomain('tickets-b', 'tickets-b.example.test', false);
        $firstUser = $this->createUser('first-ticket@example.test', $firstSite);
        $secondUser = $this->createUser('second-ticket@example.test', $secondSite);

        $this->createTicket($firstUser, $firstSite, 'First Site Ticket');
        $this->createTicket($secondUser, $secondSite, 'Second Site Ticket');

        $request = Request::create('/api/v2/admin/ticket/fetch', 'GET', [
            'filter' => [
                ['id' => 'site_id', 'value' => $firstSite->id],
            ],
            'pageSize' => 10,
            'current' => 1,
        ]);

        $payload = (new AdminTicketController())->fetch($request)->getData(true);
        $items = $payload['data']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('First Site Ticket', $items[0]['subject']);
        $this->assertSame($firstSite->id, (int) $items[0]['site_id']);
    }

    public function test_admin_order_fetch_can_filter_by_site_id(): void
    {
        $firstSite = $this->siteWithDomain('orders-a', 'orders-a.example.test', false);
        $secondSite = $this->siteWithDomain('orders-b', 'orders-b.example.test', false);
        $firstUser = $this->createUser('first-order@example.test', $firstSite);
        $secondUser = $this->createUser('second-order@example.test', $secondSite);
        $plan = $this->createPlan();

        $this->createOrder($firstUser, $firstSite, $plan, 'first-order-trade');
        $this->createOrder($secondUser, $secondSite, $plan, 'second-order-trade');

        $request = Request::create('/api/v2/admin/order/fetch', 'GET', [
            'filter' => [
                ['id' => 'site_id', 'value' => $firstSite->id],
            ],
            'pageSize' => 10,
            'current' => 1,
        ]);

        $payload = (new AdminOrderController())->fetch($request)->getData(true);
        $items = $payload['data']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('first-order-trade', $items[0]['trade_no']);
        $this->assertSame($firstSite->id, (int) $items[0]['site_id']);
    }

    private function addTicketTestColumns(): void
    {
        app('db')->connection()->getSchemaBuilder()->table('v2_ticket', function (Blueprint $table): void {
            $table->integer('reply_status')->default(Ticket::REPLY_STATUS_WAITING_ADMIN);
            $table->integer('auto_reply_count')->default(0);
            $table->integer('auto_reply_last_at')->nullable();
            $table->string('last_auto_reply_rule')->nullable();
        });

        app('db')->connection()->getSchemaBuilder()->table('v2_ticket_message', function (Blueprint $table): void {
            $table->boolean('is_auto_reply')->default(false);
            $table->string('auto_reply_rule')->nullable();
        });
    }

    private function createNoticeTable(): void
    {
        $this->database->schema()->create('v2_notice', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('scope_type', 16)->default('global')->index();
            $table->unsignedInteger('site_id')->nullable()->index();
            $table->integer('sort')->nullable()->index();
            $table->string('title');
            $table->text('content');
            $table->boolean('show')->default(false);
            $table->string('img_url')->nullable();
            $table->string('tags')->nullable();
            $table->boolean('popup')->default(false);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function siteWithDomain(string $code, string $host, bool $default): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createUser(string $email, ?Site $site): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'site_id' => $site?->id,
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Starter',
            'prices' => [Plan::PERIOD_MONTHLY => 20.00],
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createNotice(string $title, string $scopeType, ?int $siteId = null): Notice
    {
        return Notice::query()->create([
            'scope_type' => $scopeType,
            'site_id' => $siteId,
            'title' => $title,
            'content' => $title . ' content',
            'show' => true,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createTicket(User $user, Site $site, string $subject): Ticket
    {
        return Ticket::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'subject' => $subject,
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createOrder(User $user, Site $site, Plan $plan, string $tradeNo): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'type' => Order::TYPE_NEW_PURCHASE,
            'total_amount' => 2000,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function userRequest(string $uri, User $user, string $host): Request
    {
        $request = Request::create('https://' . $host . $uri, 'GET', [
            'current' => 1,
        ], [], [], [
            'HTTP_HOST' => $host,
        ]);
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    private function responsePayload($response): array
    {
        return json_decode($response->getContent(), true);
    }
}
