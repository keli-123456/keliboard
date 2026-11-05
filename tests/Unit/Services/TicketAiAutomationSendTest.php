<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ticket;
use App\Models\TicketAiConversation;
use App\Models\TicketAiConversationEvent;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use App\Services\TicketAiAssistantService;
use App\Services\TicketAiAutoReplyService;
use App\Services\TicketAiContextService;
use App\Services\TicketAiConversationService;
use App\Services\TicketAiOfficialLookup;
use App\Services\TicketAiVisionService;
use App\Services\TicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_verified_official_answer_is_sent_by_the_worker_without_a_model_call(): void
    {
        [$ticket, $source] = $this->fixture();
        config(['tickets.ai_official_lookup' => true]);
        $source->update(['message' => 'Windows: address already in use.']);
        Crypt::swap(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        $this->settings(['ticket_ai_base_url' => 'https://ai.example.test/v1', 'ticket_ai_model' => 'test-model',
            'ticket_ai_api_key' => Crypt::encryptString('sk-test'), 'ticket_ai_knowledge_enable' => false]);
        $context = $this->createMock(TicketAiContextService::class);
        $context->method('build')->willReturn(['scope' => ['type' => 'platform'],
            'conversation' => [['role' => 'user', 'content' => $source->message]], 'business' => []]);
        Http::preventStrayRequests();
        Http::fake(TicketAiOfficialLookupTest::portDocuments());

        $assistant = new TicketAiAssistantService($context);
        $capability = $assistant->capabilities();
        $this->assertTrue($capability['available'], (string) $capability['reason']);
        $worker = new TicketAiAutoReplyService($assistant);
        $worker->process((int) $ticket->id, (int) $source->id, true);
        $worker->process((int) $ticket->id, (int) $source->id, true);
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->where('is_auto_reply', true)->count(),
            (string) TicketAiConversation::where('ticket_id', $ticket->id)->value('handoff_reason'));
        $reply = TicketMessage::where('ticket_id', $ticket->id)->where('is_auto_reply', true)->sole();
        $this->assertStringContainsString(TicketAiOfficialLookupTest::WINDOWS, $reply->message);
        $this->assertStringContainsString('未占用的本地代理端口', $reply->message);
        $this->assertSame(TicketAiSuggestion::STATUS_SENT,
            TicketAiSuggestion::where('model', TicketAiOfficialLookup::REPLY_MODEL)->sole()->status);
        Http::assertSentCount(1);
    }

    public function test_official_reply_is_revalidated_against_the_source_and_current_settings(): void
    {
        [$ticket, $source, $suggestion] = $this->fixture();
        $source->update(['message' => 'Windows: address already in use.']);
        $proof = ['kind' => 'port', 'source' => 'winsock', 'source_message_id' => (int) $source->id,
            'source_hash' => hash('sha256', $source->message)];
        $suggestion->update(['model' => TicketAiOfficialLookup::REPLY_MODEL,
            'draft' => (new TicketAiOfficialLookup())->controlledDraft($proof), 'matched_knowledge' => []]);
        $approval = $this->approval($suggestion) + ['system_grounded' => true,
            'grounding_type' => TicketAiOfficialLookup::GROUNDING, 'official_reply' => $proof];

        $this->assertNull($this->send($ticket, $source, $suggestion, $approval));
        config(['tickets.ai_official_lookup' => true]);
        $this->assertNull($this->send($ticket, $source, $suggestion));
        $this->assertNull($this->send($ticket, $source, $suggestion, array_replace($approval,
            ['official_reply' => array_replace($proof, ['source' => 'https://untrusted.test'])])));
        $source->update(['message' => 'Different problem.']);
        $this->assertNull($this->send($ticket, $source, $suggestion, $approval));
        $source->update(['message' => 'Windows: address already in use.']);
        $this->assertNotNull($this->send($ticket, $source, $suggestion, $approval));
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

    public function test_verified_image_context_can_follow_a_text_reply_without_resending_the_original_image(): void
    {
        [$ticket, $followup, $suggestion, $approval] = $this->imageFollowupFixture();
        $this->assertTrue($approval['image_analysis']['context_reused']);
        $sent = (new TicketService())->replyByAiAutomation((int) $ticket->id, (int) $followup->id, (int) $suggestion->id,
            $suggestion->category, $suggestion->draft, false, $approval);
        $this->assertNotNull($sent);
        $this->assertSame(0, TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail()->follow_up_count);
    }

    public function test_image_context_is_rechecked_when_a_followup_answer_is_sent(): void
    {
        [$ticket, $followup, $suggestion, $approval, $original] = $this->imageFollowupFixture();
        $original->image_analysis = array_replace($original->image_analysis, ['status' => 'held']);
        $original->save();
        $this->assertNull((new TicketService())->replyByAiAutomation((int) $ticket->id, (int) $followup->id, (int) $suggestion->id,
            $suggestion->category, $suggestion->draft, false, $approval));
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame('generated', $suggestion->fresh()->status);
    }

    public static function anonymizedScreenshotCases(): array
    {
        // Failure mix from the eight pending non-sensitive cases; all message text is synthetic.
        return [
            '01 uncertain' => ['image_needs_human', 'Still: the screenshot error says local port is in use.'],
            '02 uncertain' => ['image_needs_human', 'Still: Clash reports an invalid configuration.'],
            '03 uncertain' => ['image_needs_human', 'The screenshot says the profile format is invalid.'],
            '04 uncertain' => ['image_needs_human', 'Still getting TLS handshake failure on this client.'],
            '05 uncertain' => ['image_needs_human', 'The screenshot shows a DNS lookup timeout.'],
            '06 unavailable' => ['vision_unavailable', 'Still: importing the profile says unsupported protocol.'],
            '07 unavailable' => ['vision_unavailable', 'The screenshot error is address already in use.'],
            '08 unsupported topic' => ['image_unsupported_topic', 'Still: the screenshot says the local proxy port is in use.'],
        ];
    }

    #[DataProvider('anonymizedScreenshotCases')]
    public function test_screenshot_clarification_then_text_followup(string $reason, string $supplement): void
    {
        [$ticket, $source, $failure] = $this->heldScreenshotFixture($reason);
        $context = $this->createMock(TicketAiContextService::class);
        $context->expects($this->once())->method('build')->willReturn(['scope' => ['type' => 'platform'], 'business' => []]);
        $assistant = $this->getMockBuilder(TicketAiAssistantService::class)
            ->setConstructorArgs([$context])->onlyMethods(['suggest'])->getMock();
        $calls = 0;
        $assistant->expects($this->exactly(2))->method('suggest')->willReturnCallback(function (Ticket $current) use ($failure, $supplement, &$calls) {
            if (++$calls === 1) {
                return $this->approval($failure) + ['image_analysis' => $failure->image_analysis];
            }

            $this->assertSame($supplement, TicketMessage::where('ticket_id', $current->id)->latest('id')->value('message'));
            $this->assertNull(app(TicketAiVisionService::class)->analyze($current, ['vision_enable' => true])['analysis']);
            $suggestion = $failure->replicate();
            $suggestion->forceFill(['status' => 'generated', 'risk' => 'low', 'needs_human' => false,
                'confidence' => 0.95, 'image_analysis' => null, 'draft' => 'A safe answer based on the new text.'])->save();
            return $this->approval($suggestion);
        });
        $automation = new TicketAiAutoReplyService($assistant);
        $automation->process((int) $ticket->id, (int) $source->id, true);
        $automation->process((int) $ticket->id, (int) $source->id, true);

        $state = TicketAiConversation::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('waiting_user', $state->status);
        $this->assertNull($state->handoff_reason);
        $this->assertSame(1, $state->follow_up_count);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(TicketAiVisionService::CLARIFICATION_TEXT, TicketMessage::findOrFail($state->last_reply_message_id)->message);
        $this->assertSame('image_clarification_sent', TicketAiConversationEvent::where('ticket_id', $ticket->id)->firstOrFail()->reason);
        $this->assertSame('superseded', $failure->fresh()->status);

        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app()));
        \Illuminate\Support\Facades\Bus::fake();
        $followup = (new TicketService())->reply($ticket->fresh(), $supplement, (int) $ticket->user_id);
        $this->assertInstanceOf(TicketMessage::class, $followup);
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\ProcessTicketAiAutoReplyJob::class);
        $automation->process((int) $ticket->id, (int) $followup->id, false);

        $state->refresh();
        $this->assertSame('active', $state->status);
        $this->assertSame(1, $state->follow_up_count);
        $this->assertSame(2, $state->auto_reply_count);
        $this->assertNull($state->handoff_reason);
        $this->assertSame('A safe answer based on the new text.', TicketMessage::findOrFail($state->last_reply_message_id)->message);
        $this->assertSame(Ticket::STATUS_OPENING, (int) $ticket->fresh()->status);
    }

    public function test_sensitive_or_mismatched_image_failures_do_not_send_clarification(): void
    {
        foreach (['image_sensitive', 'attachment_scope_mismatch', 'image_context_changed'] as $code) {
            [$ticket, $source, $failure] = $this->heldScreenshotFixture('vision_unavailable', [$code]);
            $assistant = $this->createMock(TicketAiAssistantService::class);
            $assistant->expects($this->once())->method('suggest')->willReturn($this->approval($failure) + [
                'image_analysis' => $failure->image_analysis,
            ]);
            $assistant->expects($this->never())->method('suggestClarification');
            (new TicketAiAutoReplyService($assistant))->process((int) $ticket->id, (int) $source->id, true);
            $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
            $this->assertSame('human_required', TicketAiConversation::where('ticket_id', $ticket->id)->value('status'));
        }
    }

    #[DataProvider('invalidatedScreenshotClarifications')]
    public function test_image_clarification_rechecks_source_and_analysis_before_sending(bool $newMessage): void
    {
        [$ticket, $source, $failure] = $this->heldScreenshotFixture('vision_unavailable');
        $clarification = $failure->replicate();
        $clarification->forceFill(['model' => TicketAiVisionService::CLARIFICATION_MODEL, 'draft' => TicketAiVisionService::CLARIFICATION_TEXT,
            'image_analysis' => null, 'risk' => 'low', 'needs_human' => false, 'confidence' => 1.0])->save();
        if ($newMessage) {
            TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => 'The error text is invalid configuration.']);
        } else {
            $newer = $failure->replicate();
            $newer->image_analysis = array_replace($failure->image_analysis, ['reason' => 'image_sensitive']);
            $newer->save();
        }
        $approval = $this->approval($clarification) + [
            'grounding_type' => TicketAiVisionService::CLARIFICATION_GROUNDING,
            'image_failure_suggestion_id' => (int) $failure->id,
        ];
        $this->assertNull((new TicketService())->replyByAiAutomation((int) $ticket->id, (int) $source->id,
            (int) $clarification->id, $clarification->category, $clarification->draft, true, $approval));
        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->where('is_auto_reply', 1)->count());
    }

    public static function invalidatedScreenshotClarifications(): array
    {
        return ['newer sensitive analysis' => [false], 'newer user message' => [true]];
    }

    public function test_second_failed_screenshot_does_not_repeat_the_text_request(): void
    {
        [$ticket, $source, $failure] = $this->heldScreenshotFixture('vision_unavailable');
        $state = TicketAiConversation::create(['ticket_id' => $ticket->id, 'status' => 'active']);
        TicketAiConversationEvent::create(['ticket_id' => $ticket->id, 'conversation_id' => $state->id,
            'event' => 'follow_up_sent', 'reason' => 'image_clarification_sent', 'source_message_id' => $source->id - 1]);

        $assistant = $this->createMock(TicketAiAssistantService::class);
        $assistant->expects($this->once())->method('suggest')->willReturn($this->approval($failure) + [
            'image_analysis' => $failure->image_analysis,
        ]);
        $assistant->expects($this->never())->method('suggestClarification');
        (new TicketAiAutoReplyService($assistant))->process((int) $ticket->id, (int) $source->id, false);

        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame('risk_not_low', $state->fresh()->handoff_reason);
    }

    private function heldScreenshotFixture(string $reason, array $extraReasons = []): array
    {
        [$ticket, $source, $failure, $analysis] = $this->screenshotFixture();
        $failure->forceFill(['risk' => 'medium', 'needs_human' => true, 'confidence' => 0,
            'image_analysis' => array_replace($analysis, ['status' => 'held', 'needs_human' => true, 'confidence' => 0,
                'reason' => $reason, 'reason_codes' => [$reason, ...$extraReasons], 'context_reused' => false, 'observations' => []]),
        ])->save();
        return [$ticket, $source, $failure];
    }

    private function imageFollowupFixture(): array
    {
        [$ticket, , $original, $analysis] = $this->screenshotFixture();
        $analysis += ['analysis_version' => 'ticket-vision-v2', 'context_reused' => false,
            'observations' => ['client' => 'v2rayN', 'error_text' => 'Local port already in use']];
        $original->image_analysis = $analysis; $original->save();
        $followup = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => '还是截图里的问题']);
        $reused = app(\App\Services\TicketAiVisionService::class)->analyze($ticket, ['vision_enable' => true])['analysis'];
        $suggestion = $original->replicate();
        $suggestion->image_analysis = $reused; $suggestion->save();
        return [$ticket, $followup, $suggestion, array_merge($this->approval($suggestion), ['image_analysis' => $reused]), $original];
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
