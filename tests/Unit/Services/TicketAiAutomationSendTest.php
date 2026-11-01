<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ticket;
use App\Models\TicketAiConversation;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use App\Services\TicketAiAssistantService;
use App\Services\TicketAiAutoReplyService;
use App\Services\TicketAiConversationService;
use App\Services\TicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiAutomationSendTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createTables();
        $this->settings();
    }

    public function test_safe_reply_is_sent_once_with_tracking_in_the_same_transaction(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $reply = $this->send($ticket, $source, $suggestion);
        $this->assertNotNull($reply);
        $this->assertTrue($reply->is_auto_reply);
        $this->assertSame(TicketAiSuggestion::STATUS_SENT, $suggestion->fresh()->status);
        $this->assertSame(Ticket::REPLY_STATUS_AUTO_REPLIED, $ticket->fresh()->reply_status);
        $state = TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(1, $state->auto_reply_count);
        $this->assertSame((int) $reply->id, $state->last_reply_message_id);
        $this->assertNull($this->send($ticket, $source, $suggestion));
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    public function test_persisted_safety_decisions_cannot_be_overridden_by_approval_data(): void
    {
        foreach ([
            ['risk' => 'high'], ['needs_human' => true], ['confidence' => 0.2],
            ['structured_output' => false], ['matched_knowledge' => []], ['category' => '支付退款'],
            ['admin_id' => 99], ['status' => 'superseded'], ['status' => 'discarded'],
            ['scope_type' => 'site', 'site_id' => 9],
        ] as $attributes) {
            [$ticket, $source, $suggestion] = $this->fixture();
            $approval = $this->approval($suggestion);
            $suggestion->forceFill($attributes)->save();
            $this->assertNull($this->send($ticket, $source, $suggestion, $approval), json_encode($attributes));
            $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        }
    }

    public function test_settings_and_tenant_policy_are_rechecked_at_send_time(): void
    {
        foreach ([
            ['ticket_ai_enable' => false], ['ticket_ai_auto_reply_enable' => false],
            ['ticket_ai_scope_policies' => [['scope_type' => 'platform', 'enabled' => false]]],
            ['ticket_ai_auto_reply_min_confidence' => 1],
        ] as $settings) {
            [$ticket, $source, $suggestion] = $this->fixture();
            $this->settings($settings);
            $this->assertNull($this->send($ticket, $source, $suggestion), json_encode($settings));
            $this->settings();
        }
    }

    public function test_sensitive_source_or_subject_is_blocked_even_when_the_model_claims_low_risk(): void
    {
        foreach ([['subject' => '申请退款'], ['message' => 'Please unban my account']] as $changes) {
            [$ticket, $source, $suggestion] = $this->fixture();
            if (isset($changes['subject'])) {
                $ticket->subject = $changes['subject'];
                $ticket->save();
            } else {
                $source->message = $changes['message'];
                $source->save();
            }
            $this->assertNull($this->send($ticket, $source, $suggestion));
        }
    }

    public function test_a_human_reply_during_generation_stays_in_control_after_the_user_replies(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        (new TicketService())->replyByAdmin((int) $ticket->id, '人工已经接管', 99);
        $this->assertNull($this->send($ticket, $source, $suggestion));

        $next = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => '补充详情']);
        $ticket->reply_status = Ticket::REPLY_STATUS_WAITING_ADMIN;
        $ticket->save();
        $this->assertNull($this->send($ticket, $next, $suggestion));
        $state = TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('human_replied', $state->handoff_reason);
        $this->assertSame(0, $state->auto_reply_count);
    }

    public function test_source_draft_and_ticket_must_still_match(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $approval = $this->approval($suggestion);
        $approval['draft'] = '替换后的文本';
        $this->assertNull($this->send($ticket, $source, $suggestion, $approval));
        $approval = $this->approval($suggestion);
        $approval['suggestion_id'] = 9999;
        $this->assertNull($this->send($ticket, $source, $suggestion, $approval));
        [$other] = $this->fixture();
        $this->assertNull($this->send($other, $source, $suggestion));
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => '新增问题']);
        $this->assertNull($this->send($ticket, $source, $suggestion));
    }

    public function test_state_tracking_failure_rolls_back_the_reply_and_suggestion(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $conversation = $this->createPartialMock(TicketAiConversationService::class, ['recordSent']);
        $conversation->method('recordSent')->willThrowException(new \RuntimeException('tracking failed'));
        app()->instance(TicketAiConversationService::class, $conversation);
        try {
            $this->send($ticket, $source, $suggestion);
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('tracking failed', $exception->getMessage());
        }
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(TicketAiSuggestion::STATUS_GENERATED, $suggestion->fresh()->status);
        $this->assertSame(Ticket::REPLY_STATUS_WAITING_ADMIN, $ticket->fresh()->reply_status);
    }

    public function test_unsafe_generation_is_held_as_a_reviewable_draft_not_sent_or_discarded(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $assistant = $this->createMock(TicketAiAssistantService::class);
        $assistant->expects($this->once())->method('suggest')->willReturn(array_merge(
            $this->approval($suggestion), ['needs_human' => true]
        ));
        $assistant->expects($this->never())->method('discardAutomationSuggestion');
        $sender = $this->createMock(TicketService::class);
        $sender->expects($this->never())->method('replyByAiAutomation');
        (new TicketAiAutoReplyService($assistant, $sender))->process((int) $ticket->id, (int) $source->id, true);
        $this->assertSame('needs_human', TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail()->handoff_reason);
        $this->assertSame(TicketAiSuggestion::STATUS_GENERATED, $suggestion->fresh()->status);
    }

    public function test_unavailable_sent_state_rolls_back_instead_of_silently_losing_tracking(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $conversation = $this->createPartialMock(TicketAiConversationService::class, ['recordSent']);
        $conversation->method('recordSent')->willReturn(false);
        app()->instance(TicketAiConversationService::class, $conversation);
        try {
            $this->send($ticket, $source, $suggestion);
            $this->fail('Expected unavailable tracking to block sending.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertStringContainsString('state update failed', $exception->getMessage());
        }
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(TicketAiSuggestion::STATUS_GENERATED, $suggestion->fresh()->status);
    }

    public function test_sensitive_ticket_is_handed_off_without_calling_the_provider(): void
    {
        [$ticket, $source] = $this->fixture();
        $ticket->subject = '申请退款';
        $ticket->save();
        $assistant = $this->createMock(TicketAiAssistantService::class);
        $assistant->expects($this->never())->method('suggest');
        $assistant->expects($this->never())->method('suggestClarification');
        (new TicketAiAutoReplyService($assistant))->process((int) $ticket->id, (int) $source->id, true);
        $this->assertSame('needs_human', TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail()->handoff_reason);
    }

    public function test_verified_screenshot_can_be_sent_and_is_not_counted_as_a_clarification(): void
    {
        [$ticket, $source, $suggestion, $analysis] = $this->screenshotFixture();
        $approval = array_merge($this->approval($suggestion), ['image_analysis' => $analysis]);
        $sent = (new TicketService())->replyByAiAutomation((int) $ticket->id, (int) $source->id, (int) $suggestion->id,
            $suggestion->category, $suggestion->draft, true, $approval);
        $this->assertNotNull($sent);
        $state = TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(1, $state->auto_reply_count);
        $this->assertSame(0, $state->follow_up_count);
    }

    public function test_changed_or_unverified_screenshot_cannot_be_sent_even_with_otherwise_safe_text(): void
    {
        [$ticket, $source, $suggestion, $analysis] = $this->screenshotFixture();
        $sender = new TicketService();
        $approval = array_merge($this->approval($suggestion), ['image_analysis' => $analysis]);
        $path = \App\Models\TicketMessageAttachment::where('ticket_message_id', $source->id)->firstOrFail()->path;
        Storage::disk('local')->put($path, 'changed');
        $this->assertNull($sender->replyByAiAutomation((int) $ticket->id, (int) $source->id, (int) $suggestion->id,
            $suggestion->category, $suggestion->draft, true, $approval));
        $suggestion->image_analysis = null;
        $suggestion->save();
        $this->assertNull($sender->replyByAiAutomation((int) $ticket->id, (int) $source->id, (int) $suggestion->id,
            $suggestion->category, $suggestion->draft, true, $approval));
    }

    private function screenshotFixture(): array
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD required.');
        $this->settings(['ticket_ai_vision_enable' => true]);
        [$ticket, $source, $suggestion] = $this->fixture();
        Storage::fake('local');
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        $path = "ticket_attachments/{$ticket->id}/{$source->id}/test.png";
        Storage::disk('local')->put($path, $bytes);
        $attachment = \App\Models\TicketMessageAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $source->id,
            'user_id' => $ticket->user_id, 'disk' => 'local', 'path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes)]);
        $analysis = ['status' => 'ready', 'needs_human' => false, 'confidence' => 0.99, 'source_message_id' => (int) $source->id,
            'attachment_hashes' => [(string) $attachment->id => hash('sha256', $bytes)]];
        $suggestion->image_analysis = $analysis;
        $suggestion->save();
        return [$ticket, $source, $suggestion, $analysis];
    }

    private function send(Ticket $ticket, TicketMessage $source, TicketAiSuggestion $suggestion, ?array $approval = null): ?TicketMessage
    {
        return (new TicketService())->replyByAiAutomation((int) $ticket->id, (int) $source->id, (int) $suggestion->id,
            (string) $suggestion->category, (string) $suggestion->draft, false, $approval ?? $this->approval($suggestion));
    }

    private function approval(TicketAiSuggestion $suggestion): array
    {
        return array_merge($suggestion->only([
            'structured_output', 'category', 'risk', 'needs_human', 'confidence', 'draft', 'matched_knowledge',
        ]), ['suggestion_id' => (int) $suggestion->id]);
    }

    private function fixture(): array
    {
        $ticket = Ticket::create([
            'user_id' => 10, 'subject' => '客户端咨询', 'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
        ]);
        $source = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 10, 'message' => 'Windows 客户端如何导入？']);
        $suggestion = TicketAiSuggestion::create([
            'ticket_id' => $ticket->id, 'scope_type' => 'platform', 'structured_output' => true,
            'category' => '客户端连接', 'risk' => 'low', 'needs_human' => false, 'confidence' => 0.95,
            'draft' => '请使用客户端的订阅导入功能。', 'matched_knowledge' => [['id' => 1, 'title' => '导入教程']],
        ]);
        Cache::put('ticket_sendEmailNotify_10', 1, 1800);
        return [$ticket, $source, $suggestion];
    }

    private function settings(array $overrides = []): void
    {
        $this->bindTestSettings(array_merge([
            'ticket_ai_enable' => true, 'ticket_ai_auto_reply_enable' => true,
            'ticket_ai_auto_reply_mode' => 'broad', 'ticket_ai_auto_reply_min_confidence' => 0.9,
            'ticket_ai_auto_reply_require_knowledge' => true,
        ], $overrides));
    }

    private function createTables(): void
    {
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
        });
        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->string('subject');
            $table->integer('status')->default(0);
            $table->integer('reply_status')->default(1);
            $table->integer('auto_reply_count')->default(0);
            $table->integer('auto_reply_last_at')->nullable();
            $table->string('last_auto_reply_rule')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_ticket_message', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->integer('user_id');
            $table->text('message');
            $table->boolean('is_auto_reply')->default(false);
            $table->string('auto_reply_rule')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        foreach ([
            '2026_01_01_000001_create_v2_ticket_message_attachment_table.php',
            '2026_05_02_000001_create_v2_ticket_ai_suggestion_table.php',
            '2026_07_13_000001_add_ticket_ai_scope_to_suggestions.php',
            '2026_08_18_000001_create_ticket_ai_conversation_tables.php',
            '2026_11_01_000001_add_image_analysis_to_ticket_ai_suggestions.php',
        ] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
    }
}
