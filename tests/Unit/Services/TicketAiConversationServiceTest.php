<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ticket;
use App\Models\TicketAiConversation;
use App\Models\TicketMessage;
use App\Services\TicketAiConversationService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiConversationServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createTables();
        $this->bindSettings();
    }

    public function test_it_asks_for_missing_details_only_once_per_ticket(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('节点不能用');
        $service = new TicketAiConversationService();

        $this->assertNotNull($service->clarification($ticket, $source));

        $reply = $this->message($ticket, 999, '请补充客户端名称和错误提示。', true);
        $service->recordSent($ticket, $source, $reply, [
            'draft' => $reply->message,
            'suggestion_id' => 12,
        ], true);

        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_WAITING_USER, $state->status);
        $this->assertSame(1, $state->follow_up_count);

        $next = $this->message($ticket, (int) $ticket->user_id, '还是不能用');
        $this->assertTrue($service->preflight($ticket, $next)['allow']);
        $this->assertNull($service->clarification($ticket, $next));
    }

    public function test_it_keeps_the_conversation_active_for_a_second_user_reply(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('订阅导入失败');
        $service = new TicketAiConversationService();
        $firstReply = $this->message($ticket, 999, '请重新导入订阅后再试。', true);
        $service->recordSent($ticket, $source, $firstReply, [
            'draft' => $firstReply->message,
            'suggestion_id' => 21,
        ], false);

        $ticket->reply_status = Ticket::REPLY_STATUS_WAITING_ADMIN;
        $ticket->save();
        $next = $this->message($ticket, (int) $ticket->user_id, '重新导入后仍然失败，提示超时。');

        $this->assertTrue($service->preflight($ticket, $next)['allow']);

        $secondReply = $this->message($ticket, 999, '请再确认订阅地址是否完整。', true);
        $service->recordSent($ticket, $next, $secondReply, [
            'draft' => $secondReply->message,
            'suggestion_id' => 22,
        ], false);

        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_ACTIVE, $state->status);
        $this->assertSame(2, $state->auto_reply_count);
        $this->assertNull($state->handoff_reason);
    }

    public function test_explicit_human_request_immediately_hands_off(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('不要机器人，请转人工客服');
        $service = new TicketAiConversationService();

        $result = $service->preflight($ticket, $source);

        $this->assertFalse($result['allow']);
        $this->assertTrue($result['handoff']);
        $this->assertSame('user_requested_human', $result['reason']);
        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_HUMAN_REQUIRED, $state->status);
        $this->assertSame('user_requested_human', $state->handoff_reason);
    }

    public function test_two_consecutive_low_confidence_results_hand_off_to_human(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('请帮我处理订阅问题');
        $service = new TicketAiConversationService();

        $service->recordRejected($ticket, $source, 1, 'low_confidence');
        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_ACTIVE, $state->status);
        $this->assertSame(1, $state->low_confidence_count);

        $next = $this->message($ticket, (int) $ticket->user_id, '还是没有解决');
        $service->recordRejected($ticket, $next, 2, 'low_confidence');

        $state->refresh();
        $this->assertSame(TicketAiConversation::STATUS_HUMAN_REQUIRED, $state->status);
        $this->assertSame(2, $state->low_confidence_count);
        $this->assertSame('low_confidence', $state->handoff_reason);
    }

    public function test_duplicate_reply_is_detected_after_a_message_is_sent(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('订阅导入失败');
        $service = new TicketAiConversationService();
        $draft = '请重新导入订阅后再试。';
        $reply = $this->message($ticket, 999, $draft, true);

        $service->recordSent($ticket, $source, $reply, ['draft' => $draft], false);

        $this->assertTrue($service->isDuplicateDraft($ticket, $draft));
        $this->assertFalse($service->isDuplicateDraft($ticket, '请更换客户端后再试。'));
    }

    public function test_attachment_requires_human_review(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('请看截图');
        Schema::connection(null)->getConnection()->table('v2_ticket_message_attachment')->insert([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $source->id,
            'user_id' => $ticket->user_id,
            'disk' => 'local',
            'path' => 'tickets/example.png',
            'mime' => 'image/png',
            'size' => 128,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $result = (new TicketAiConversationService())->preflight($ticket, $source);

        $this->assertFalse($result['allow']);
        $this->assertSame('attachment', $result['reason']);
    }

    public function test_broad_mode_accepts_attachments_and_asks_for_safe_details(): void
    {
        $this->bindSettings(['ticket_ai_auto_reply_mode' => 'broad']);
        [$ticket, $source] = $this->ticketWithMessage('请看');
        Schema::connection(null)->getConnection()->table('v2_ticket_message_attachment')->insert([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $source->id,
            'user_id' => $ticket->user_id,
            'disk' => 'local',
            'path' => 'tickets/example.png',
            'mime' => 'image/png',
            'size' => 128,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = new TicketAiConversationService();
        $this->assertTrue($service->preflight($ticket, $source)['allow']);
        $this->assertStringContainsString('完整错误文字', (string) $service->clarification($ticket, $source));
    }

    public function test_broad_mode_resumes_policy_handoffs_but_preserves_human_requests(): void
    {
        [$ticket, $source] = $this->ticketWithMessage('订阅不能用');
        $service = new TicketAiConversationService();
        $service->recordRejected($ticket, $source, 1, 'low_confidence');
        $next = $this->message($ticket, (int) $ticket->user_id, '还是不能用');
        $service->recordRejected($ticket, $next, 2, 'low_confidence');

        $this->bindSettings(['ticket_ai_auto_reply_mode' => 'broad']);
        $service->resumeBroadAutomation($ticket);
        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_ACTIVE, $state->status);
        $this->assertNull($state->handoff_reason);

        [$humanTicket, $humanSource] = $this->ticketWithMessage('请转人工客服', 11);
        $service->preflight($humanTicket, $humanSource);
        $service->resumeBroadAutomation($humanTicket);
        $humanState = TicketAiConversation::query()->where('ticket_id', $humanTicket->id)->firstOrFail();
        $this->assertSame(TicketAiConversation::STATUS_HUMAN_REQUIRED, $humanState->status);
        $this->assertSame('user_requested_human', $humanState->handoff_reason);
    }

    public function test_stats_report_resolution_handoff_follow_up_and_failure_reasons(): void
    {
        $service = new TicketAiConversationService();
        [$resolvedTicket, $resolvedSource] = $this->ticketWithMessage('节点不能用', 10, 3);
        $reply = $this->message($resolvedTicket, 999, '请补充客户端版本。', true);
        $service->recordSent($resolvedTicket, $resolvedSource, $reply, ['draft' => $reply->message], true);
        $service->markClosed($resolvedTicket);

        [$handoffTicket, $handoffSource] = $this->ticketWithMessage('请转人工客服', 20, 7);
        $service->preflight($handoffTicket, $handoffSource);

        [$failedTicket, $failedSource] = $this->ticketWithMessage('订阅报错', 30, null);
        $service->recordFailure((int) $failedTicket->id, (int) $failedSource->id, 'provider_retries_exhausted');

        $stats = $service->stats(7);

        $this->assertSame(3, $stats['conversations']);
        $this->assertSame(1, $stats['auto_replies']);
        $this->assertSame(1, $stats['follow_ups']);
        $this->assertSame(2, $stats['handoffs']);
        $this->assertSame(1, $stats['failures']);
        $this->assertSame(1, $stats['resolved_without_handoff']);
        $this->assertSame(1.0, $stats['automatic_resolution_rate']);
        $this->assertSame(0.6667, $stats['handoff_rate']);
        $this->assertSame('user_requested_human', $stats['top_handoff_reasons'][0]['reason']);
        $this->assertSame('provider_retries_exhausted', $stats['top_failure_reasons'][0]['reason']);
    }

    /** @return array{Ticket, TicketMessage} */
    private function ticketWithMessage(string $message, int $userId = 10, ?int $siteId = null): array
    {
        $ticket = Ticket::query()->create([
            'user_id' => $userId,
            'site_id' => $siteId,
            'subject' => '连接问题',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return [$ticket, $this->message($ticket, $userId, $message)];
    }

    private function message(Ticket $ticket, int $userId, string $message, bool $autoReply = false): TicketMessage
    {
        return TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'message' => $message,
            'is_auto_reply' => $autoReply,
            'auto_reply_rule' => $autoReply ? 'ai:test' : null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function bindSettings(array $overrides = []): void
    {
        $values = array_merge([
            'ticket_ai_auto_reply_mode' => 'strict',
            'ticket_ai_auto_reply_max_per_ticket' => 3,
        ], $overrides);

        app()->instance(Setting::class, new class($values) extends Setting {
            public function __construct(private array $values)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
            }
        });
    }

    private function createTables(): void
    {
        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->string('subject')->nullable();
            $table->integer('level')->default(0);
            $table->integer('status')->default(0);
            $table->integer('reply_status')->nullable();
            $table->integer('auto_reply_count')->nullable();
            $table->integer('auto_reply_last_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        Schema::create('v2_ticket_message', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->integer('user_id')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_auto_reply')->default(false);
            $table->string('auto_reply_rule')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        Schema::create('v2_ticket_message_attachment', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->integer('ticket_message_id');
            $table->integer('user_id');
            $table->string('disk');
            $table->string('path');
            $table->string('mime');
            $table->integer('size');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        Schema::create('v2_ticket_ai_conversation', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id')->unique();
            $table->string('scope_type')->default('platform');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->string('status')->default('active');
            $table->integer('auto_reply_count')->default(0);
            $table->integer('follow_up_count')->default(0);
            $table->integer('low_confidence_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->integer('last_source_message_id')->nullable();
            $table->integer('last_reply_message_id')->nullable();
            $table->string('last_draft_hash')->nullable();
            $table->string('last_reason')->nullable();
            $table->string('handoff_reason')->nullable();
            $table->integer('handoff_at')->nullable();
            $table->integer('last_activity_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        Schema::create('v2_ticket_ai_conversation_event', function (Blueprint $table): void {
            $table->id();
            $table->integer('conversation_id');
            $table->integer('ticket_id');
            $table->integer('source_message_id')->nullable();
            $table->integer('suggestion_id')->nullable();
            $table->integer('reply_message_id')->nullable();
            $table->string('event');
            $table->string('reason')->nullable();
            $table->string('scope_type')->default('platform');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}
