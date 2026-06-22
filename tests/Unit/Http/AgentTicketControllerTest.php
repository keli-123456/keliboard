<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\AgentTicketController;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentTicketControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindNoopDispatcher();
        $this->bindTestSettings([
            'ticket_must_wait_reply' => 0,
        ]);
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createTicketTables();
        $this->addTicketColumns();
    }

    public function test_agent_can_list_view_and_reply_owned_subordinate_ticket(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket = $this->createTicket($buyer, $agent, 'Need help');

        $list = $this->responsePayload(app(AgentTicketController::class)->index($this->requestFor($agent)));

        $this->assertSame(1, $list['data']['total']);
        $this->assertSame($ticket->id, $list['data']['items'][0]['id']);
        $this->assertSame('buyer@example.test', $list['data']['items'][0]['user']['email']);

        $detail = $this->responsePayload(app(AgentTicketController::class)->show($this->requestFor($agent), $ticket->id));

        $this->assertSame($ticket->id, $detail['data']['id']);
        $this->assertFalse($detail['data']['messages'][0]['is_me']);

        $reply = $this->responsePayload(app(AgentTicketController::class)->reply(
            $this->requestFor($agent, 'POST', ['message' => 'Agent reply']),
            $ticket->id
        ));

        $this->assertSame($ticket->id, $reply['data']['id']);
        $this->assertSame('Agent reply', $reply['data']['messages'][1]['message']);
        $this->assertTrue($reply['data']['messages'][1]['is_me']);
        $this->assertSame(Ticket::REPLY_STATUS_WAITING_USER, (int) Ticket::query()->findOrFail($ticket->id)->reply_status);
    }

    public function test_agent_ticket_access_never_crosses_agent_ownership(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other@example.test');
        $buyer = $this->createUser('buyer@example.test');
        $ticket = $this->createTicket($buyer, $otherAgent, 'Other help');

        $list = $this->responsePayload(app(AgentTicketController::class)->index($this->requestFor($agent)));

        $this->assertSame(0, $list['data']['total']);

        $detail = $this->responsePayload(app(AgentTicketController::class)->show($this->requestFor($agent), $ticket->id));

        $this->assertSame('fail', $detail['status']);
        $this->assertSame('Ticket does not exist', $detail['message']);
    }

    private function addTicketColumns(): void
    {
        app('db')->connection()->getSchemaBuilder()->table('v2_ticket', function (Blueprint $table): void {
            $table->integer('reply_status')->nullable();
            $table->integer('auto_reply_count')->default(0);
            $table->integer('auto_reply_last_at')->nullable();
            $table->string('last_auto_reply_rule')->nullable();
        });

        app('db')->connection()->getSchemaBuilder()->table('v2_ticket_message', function (Blueprint $table): void {
            $table->boolean('is_auto_reply')->default(false);
            $table->string('auto_reply_rule')->nullable();
        });
    }

    private function bindNoopDispatcher(): void
    {
        app()->instance(Dispatcher::class, new class implements Dispatcher {
            public function dispatch($command): mixed
            {
                return $command;
            }

            public function dispatchSync($command, $handler = null): mixed
            {
                return $command;
            }

            public function dispatchNow($command, $handler = null): mixed
            {
                return $command;
            }

            public function hasCommandHandler($command): bool
            {
                return false;
            }

            public function getCommandHandler($command): mixed
            {
                return null;
            }

            public function pipeThrough(array $pipes): static
            {
                return $this;
            }

            public function map(array $map): static
            {
                return $this;
            }
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

    private function createTicket(User $buyer, User $agent, string $subject): Ticket
    {
        $ticket = Ticket::query()->create([
            'user_id' => $buyer->id,
            'agent_user_id' => $agent->id,
            'subject' => $subject,
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'message' => 'Initial message',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $ticket;
    }

    private function requestFor(User $user, string $method = 'GET', array $payload = []): Request
    {
        $request = Request::create('/api/v1/user/agent/tickets', $method, $payload);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
