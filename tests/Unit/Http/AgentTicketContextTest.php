<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\TicketController as AdminTicketController;
use App\Http\Resources\TicketResource;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use App\Services\TicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentTicketContextTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings([
            'ticket_auto_reply_enable' => 0,
        ]);
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createTicketTables();
        $this->addTicketTestColumns();
        $this->createTicketAiSuggestionTable();
    }

    public function test_agent_user_ticket_records_agent_context(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(AgentCommerceContextResolver::class)->resolveUser($buyer);
        $ticket = app(TicketService::class)->createTicket(
            $buyer->id,
            'Need help',
            1,
            'Please check my account',
            [],
            ['agent_context' => $context]
        );

        $this->assertSame($agent->id, (int) $ticket->agent_user_id);
        $this->assertNull($ticket->agent_domain_id);
    }

    public function test_agent_domain_ticket_records_domain_context(): void
    {
        $agent = $this->createActiveAgent('domain-agent@example.test');
        $buyer = $this->createUser('domain-buyer@example.test');
        $domain = $this->assignDomain($agent, 'store.example.test');
        $request = $this->requestForHost('store.example.test', $buyer);

        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $buyer);
        $ticket = app(TicketService::class)->createTicket(
            $buyer->id,
            'Domain support',
            2,
            'This came from the storefront',
            [],
            ['agent_context' => $context]
        );

        $this->assertSame($agent->id, (int) $ticket->agent_user_id);
        $this->assertSame($domain->id, (int) $ticket->agent_domain_id);
    }

    public function test_admin_fetch_filter_agent_user_id_returns_only_matching_tickets(): void
    {
        $firstAgent = $this->createActiveAgent('first-agent@example.test');
        for ($i = 0; $i < 8; $i++) {
            $this->createUser('filler-' . $i . '@example.test');
        }
        $secondAgent = $this->createActiveAgent('second-agent@example.test');
        $firstBuyer = $this->createUser('first-buyer@example.test');
        $secondBuyer = $this->createUser('second-buyer@example.test');

        Ticket::query()->create([
            'user_id' => $firstBuyer->id,
            'agent_user_id' => $firstAgent->id,
            'subject' => 'First agent ticket',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Ticket::query()->create([
            'user_id' => $secondBuyer->id,
            'agent_user_id' => $secondAgent->id,
            'subject' => 'Second agent ticket',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v2/admin/ticket/fetch', 'GET', [
            'filter' => [
                ['id' => 'agent_user_id', 'value' => $firstAgent->id],
            ],
            'pageSize' => 10,
            'current' => 1,
        ]);

        $payload = (new AdminTicketController())->fetch($request)->getData(true);
        $items = $payload['data']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('First agent ticket', $items[0]['subject']);
        $this->assertSame($firstAgent->id, $items[0]['agent']['id']);
        $this->assertSame($firstAgent->email, $items[0]['agent']['email']);
        $this->assertNull($items[0]['agent_domain']);
    }

    public function test_resource_includes_agent_and_agent_domain_when_loaded(): void
    {
        config(['hidden_features.enable_exposed_user_count_fix' => true]);
        $agent = $this->createActiveAgent('resource-agent@example.test');
        $buyer = $this->createUser('resource-buyer@example.test');
        $domain = $this->assignDomain($agent, 'resource.example.test');
        $ticket = Ticket::query()->create([
            'user_id' => $buyer->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'subject' => 'Resource ticket',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket->load(['agent:id,email', 'agentDomain:id,domain']);

        $data = TicketResource::make($ticket)->toArray(Request::create('/ticket', 'GET'));

        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertSame(['id' => $agent->id, 'email' => $agent->email], $data['agent']);
        $this->assertSame(['id' => $domain->id, 'domain' => $domain->domain], $data['agent_domain']);
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

    private function createTicketAiSuggestionTable(): void
    {
        app('db')->connection()->getSchemaBuilder()->create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id')->index();
            $table->string('category', 64)->nullable();
            $table->string('risk', 32)->nullable();
            $table->boolean('needs_human')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);
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

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host, User $user): Request
    {
        $request = Request::create('https://' . $host . '/user/ticket/save', 'POST');
        $request->headers->set('host', $host);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
