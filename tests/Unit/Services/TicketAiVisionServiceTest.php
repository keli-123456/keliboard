<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketAiSuggestion;
use App\Services\TicketAiProviderClient;
use App\Services\TicketAiVisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiVisionServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('gd')) $this->markTestSkipped('GD is required for the screenshot pipeline.');
        $this->setUpInMemoryDatabase();
        foreach (['v2_ticket', 'v2_ticket_message'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->integer('user_id');
                if ($name === 'v2_ticket_message') {
                    $table->integer('ticket_id');
                    $table->text('message')->nullable();
                    $table->boolean('is_auto_reply')->default(false);
                    $table->string('auto_reply_rule')->nullable();
                } else {
                    $table->integer('site_id')->nullable();
                    $table->integer('agent_user_id')->nullable();
                    $table->integer('agent_domain_id')->nullable();
                }
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
        foreach (['2026_01_01_000001_create_v2_ticket_message_attachment_table.php',
            '2026_05_02_000001_create_v2_ticket_ai_suggestion_table.php',
            '2026_07_13_000001_add_ticket_ai_scope_to_suggestions.php',
            '2026_11_01_000001_add_image_analysis_to_ticket_ai_suggestions.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Storage::fake('local');
    }

    public function test_real_image_probe_checks_a_random_code_and_cache_is_bound_to_provider_model_and_key(): void
    {
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->exactly(3))->method('complete')->willReturnCallback(function ($settings, $messages) {
            $this->assertLessThanOrEqual(12, $settings['timeout']);
            $code = $this->readProbeCode($messages[1]['content'][1]['image_url']['url']);
            $this->assertStringNotContainsString($code, json_encode($messages[0]));
            return $this->completion(['code' => $code]);
        });
        $service = new TicketAiVisionService($provider);
        $settings = $this->settings();
        $first = $service->verifySupport($settings);
        $this->assertTrue($first['supported']);
        $this->assertCount(1, $first['completions']);
        $this->assertSame('ready', $service->status($settings));
        $this->assertSame([], $service->verifySupport($settings)['completions']);
        $this->assertTrue($service->verifySupport(array_merge($settings, ['model' => 'other-model']))['supported']);
        $this->assertTrue($service->verifySupport(array_merge($settings, ['api_key' => 'other-key']))['supported']);
    }

    public function test_text_only_provider_is_not_accepted_and_real_customer_images_are_not_sent(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->once())->method('complete')->willReturn($this->completion(['code' => 'not-the-image']));
        $service = new TicketAiVisionService($provider);
        $result = $service->analyze($ticket, $this->settings());
        $this->assertSame('held', $result['analysis']['status']);
        $this->assertSame('vision_unavailable', $result['analysis']['reason']);
        $this->assertSame('vision_unsupported', $result['completions'][0]['error_code']);
        $this->assertSame('unavailable', $service->status($this->settings()));
    }

    public function test_sol_vision_probe_and_analysis_preserve_the_combined_reasoning_budget(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->exactly(2))->method('complete')->willReturnCallback(function ($settings, $messages) {
            $this->assertSame(4096, $settings['max_tokens']);
            $this->assertSame('gpt-6.1-sol', $settings['model']);
            $this->assertSame('low', $settings['reasoning_effort']);
            if (str_contains($messages[0]['content'], 'six-character')) {
                return $this->completion(['code' => $this->readProbeCode($messages[1]['content'][1]['image_url']['url'])]);
            }
            return $this->completion($this->observations());
        });
        $result = (new TicketAiVisionService($provider))->analyze($ticket,
            array_merge($this->settings(), ['model' => 'gpt-6.1-sol', 'max_tokens' => 4096, 'reasoning_effort' => 'low']));
        $this->assertSame('ready', $result['analysis']['status']);
    }

    public function test_images_are_resized_reencoded_and_have_no_public_url_or_original_metadata(): void
    {
        [$ticket] = $this->fixture($this->png(3000, 600) . 'private-original-metadata');
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->once())->method('complete')->willReturnCallback(function ($settings, $messages) {
            $this->assertLessThanOrEqual(25, $settings['timeout']);
            $url = $messages[1]['content'][1]['image_url']['url'];
            $this->assertStringStartsWith('data:image/jpeg;base64,', $url);
            $jpeg = base64_decode(explode(',', $url, 2)[1], true);
            $size = getimagesizefromstring($jpeg);
            $this->assertSame(1600, $size[0]);
            $this->assertSame(320, $size[1]);
            $this->assertStringNotContainsString('private-original-metadata', $jpeg);
            $this->assertStringNotContainsString('ticket_attachments', json_encode($messages));
            return $this->completion($this->observations());
        });
        $result = $this->verifiedService($provider)->analyze($ticket, $this->settings());
        $this->assertSame('ready', $result['analysis']['status']);
        $this->assertFalse($result['analysis']['needs_human']);
        $this->assertSame('sing-box', $result['analysis']['observations']['core']);
        $this->assertStringNotContainsString('data:image', json_encode($result));
        $this->assertStringNotContainsString('private-original-metadata', json_encode($result));
    }

    public function test_disabled_and_unmigrated_installations_never_send_images(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->never())->method('complete');
        $service = new TicketAiVisionService($provider);
        $this->assertNull($service->analyze($ticket, array_merge($this->settings(), ['vision_enable' => false]))['analysis']);
        Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropColumn('image_analysis'));
        $this->assertSame('held', $service->analyze($ticket, $this->settings())['analysis']['status']);
    }

    public function test_mismatched_owners_ticket_ids_and_paths_are_rejected_before_any_provider_call(): void
    {
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->never())->method('complete');
        $service = new TicketAiVisionService($provider);
        foreach ([['user_id' => 99], ['ticket_id' => 999], ['disk' => 'public'],
            ['path' => '../private-file.png'], ['path' => 'https://example.test/image.png']] as $change) {
            [$ticket, , $attachment] = $this->fixture();
            $attachment->forceFill($change)->save();
            $result = $service->analyze($ticket, $this->settings());
            $this->assertSame('held', $result['analysis']['status']);
        }
    }

    public function test_invalid_oversized_and_excessive_images_are_not_sent(): void
    {
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->never())->method('complete');
        $service = new TicketAiVisionService($provider);
        foreach (['not an image', str_repeat('x', 8 * 1024 * 1024 + 1),
            substr_replace($this->png(), pack('N', 100000), 16, 4)] as $bytes) {
            [$ticket] = $this->fixture($bytes);
            $this->assertSame('invalid_image', $service->analyze($ticket, $this->settings())['analysis']['reason']);
        }
        [$ticket, $source, $attachment] = $this->fixture();
        foreach (range(1, 3) as $index) {
            TicketMessageAttachment::create(array_merge($attachment->getAttributes(), ['id' => null, 'path' => dirname($attachment->path) . "/{$index}.png"]));
        }
        $this->assertSame('image_limit', $service->analyze($ticket, $this->settings())['analysis']['reason']);
    }

    public function test_historical_and_staff_attachments_are_not_forwarded(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->never())->method('complete');
        $service = new TicketAiVisionService($provider);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => '新问题']);
        $this->assertNull($service->analyze($ticket, $this->settings())['analysis']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 99, 'message' => '人工回复']);
        $this->assertNull($service->analyze($ticket, $this->settings())['analysis']);
    }

    public function test_unclear_sensitive_payment_or_malformed_observations_always_require_human_review(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $responses = [];
        $cases = [
            [['readable' => false], 'image_unreadable'], [['confidence' => 0.5], 'image_low_confidence'],
            [['confidence' => '0.99'], 'vision_invalid_response'], [['sensitive' => true], 'image_sensitive'],
            [['needs_human' => true], 'image_needs_human'], [['problem_type' => 'payment'], 'image_sensitive'],
            [['observations' => 'wrong schema'], 'vision_invalid_response'],
            [['observations' => ['error_text' => '请帮我退款 password=private-secret https://private.example.test/sub/secret']], 'image_sensitive'],
        ];
        foreach ($cases as [$change]) {
            $responses[] = $this->completion(array_replace_recursive($this->observations(), $change));
        }
        $provider->method('complete')->willReturnOnConsecutiveCalls(...$responses);
        $service = $this->verifiedService($provider);
        foreach ($cases as [, $reason]) {
            $result = $service->analyze($ticket, $this->settings());
            $this->assertSame('held', $result['analysis']['status']);
            $this->assertTrue($result['analysis']['needs_human']);
            $this->assertSame($reason, $result['analysis']['reason']);
            $this->assertContains($reason, $result['analysis']['reason_codes']);
            $this->assertSame([], $result['analysis']['observations']);
            $this->assertStringNotContainsString('private-secret', json_encode($result));
            $this->assertStringNotContainsString('private.example.test', json_encode($result));
        }
    }

    public function test_provider_timeout_is_recorded_without_saving_images_or_raw_errors(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->method('complete')->willThrowException(new \App\Exceptions\TicketAiProviderException('timeout'));
        $service = $this->verifiedService($provider);
        $result = $service->analyze($ticket, $this->settings());
        $this->assertSame('held', $result['analysis']['status']);
        $this->assertSame('timeout', $result['completions'][0]['error_code']);
        $this->assertSame('vision_timeout', $result['analysis']['reason']);
        $this->assertArrayNotHasKey('content', $result['completions'][0]);
    }

    public function test_send_time_proof_rejects_modified_removed_or_foreign_attachments(): void
    {
        [$ticket, $source, $attachment] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->method('complete')->willReturn($this->completion($this->observations()));
        $service = $this->verifiedService($provider);
        $analysis = $service->analyze($ticket, $this->settings())['analysis'];
        $this->assertTrue($service->sourceStillMatches($ticket, $source, $analysis));
        Storage::disk('local')->put($attachment->path, $this->png(20, 20));
        $this->assertFalse($service->sourceStillMatches($ticket, $source, $analysis));
        Storage::disk('local')->delete($attachment->path);
        $this->assertFalse($service->sourceStillMatches($ticket, $source, $analysis));
        $attachment->delete();
        $this->assertFalse($service->sourceStillMatches($ticket, $source, $analysis));
    }

    public function test_image_payloads_do_not_inflate_text_metrics_or_create_cast_warnings(): void
    {
        $this->assertSame(7, (new TicketAiProviderClient())->promptChars([
            ['role' => 'system', 'content' => 'abc'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'test'], ['type' => 'image_url', 'image_url' => ['url' => str_repeat('x', 1000)]]]],
        ]));
    }

    public function test_image_metadata_migration_is_reversible_and_repeatable_without_touching_existing_fields(): void
    {
        $migration = require base_path('database/migrations/2026_11_01_000001_add_image_analysis_to_ticket_ai_suggestions.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'image_analysis'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'image_analysis'));
        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'draft'));
        $migration->up();
        $this->assertTrue((new TicketAiVisionService())->storageReady());
    }

    public function test_a_clear_error_does_not_require_missing_optional_version_fields(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->once())->method('complete')->willReturnCallback(function ($settings, $messages) {
            $this->assertStringContainsString('Missing optional', $messages[0]['content']);
            return $this->completion(array_replace_recursive($this->observations(), ['observations' => ['app_version' => '', 'core_version' => '']]));
        });
        $this->assertSame('ready', $this->verifiedService($provider)->analyze($ticket, $this->settings())['analysis']['status']);
    }

    public function test_multiple_hold_reasons_are_bounded_and_do_not_retain_extracted_text(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->method('complete')->willReturn($this->completion(array_replace($this->observations(), ['sensitive' => true, 'readable' => false, 'confidence' => 0.6])));
        $analysis = $this->verifiedService($provider)->analyze($ticket, $this->settings())['analysis'];
        $this->assertSame(['image_sensitive', 'image_unreadable', 'image_low_confidence'], $analysis['reason_codes']);
        $this->assertSame(0.6, $analysis['confidence']);
        $this->assertSame([], $analysis['observations']);
        $this->assertStringNotContainsString('TLS handshake', $analysis['summary']);
    }

    public function test_invalid_model_output_is_logged_as_an_invalid_vision_response(): void
    {
        [$ticket] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->method('complete')->willReturn($this->completion(['observations' => 'not an object']));
        $result = $this->verifiedService($provider)->analyze($ticket, $this->settings());
        $this->assertSame('vision_invalid_response', $result['analysis']['reason']);
        $this->assertSame('failed', $result['completions'][0]['status']);
        $this->assertSame('vision_invalid_response', $result['completions'][0]['error_code']);
    }

    public function test_a_related_text_followup_reuses_only_verified_observations_without_a_provider_call(): void
    {
        [$ticket, $original, , $service, $analysis, $stored] = $this->rememberedFixture();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 99, 'message' => 'AI reply', 'is_auto_reply' => true, 'auto_reply_rule' => 'ai:客户端连接']);
        $followup = $this->followup($ticket);
        $result = $service->analyze($ticket, $this->settings());
        $this->assertSame([], $result['completions']);
        $reused = $result['analysis'];
        $this->assertSame('ready', $reused['status']);
        $this->assertTrue($reused['context_reused']);
        $this->assertSame((int) $followup->id, $reused['source_message_id']);
        $this->assertSame((int) $original->id, $reused['origin_message_id']);
        $this->assertSame((int) $stored->id, $reused['origin_suggestion_id']);
        $this->assertSame($analysis['observations'], $reused['observations']);
        $this->assertTrue($service->sourceStillMatches($ticket, $followup, $reused));
        $this->assertStringNotContainsString('data:image', json_encode($result));
        $this->assertFalse($service->sourceStillMatches($ticket, $followup, array_replace($reused, ['context_expires_at' => $reused['context_expires_at'] + 1])));
    }

    public function test_unrelated_questions_and_manual_intervention_do_not_reuse_old_images(): void
    {
        [$ticket, , , $service] = $this->rememberedFixture();
        $this->followup($ticket, '新的问题，套餐价格是多少？');
        $this->assertNull($service->analyze($ticket, $this->settings())['analysis']);
        $this->followup($ticket);
        $this->assertNull($service->analyze($ticket, $this->settings())['analysis']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 99, 'message' => 'Human reply']);
        $this->followup($ticket);
        $this->assertNull($service->analyze($ticket, $this->settings())['analysis']);
    }

    public function test_deleted_or_replaced_images_invalidate_a_followup_before_sending(): void
    {
        [$ticket, , $attachment, $service] = $this->rememberedFixture();
        $followup = $this->followup($ticket);
        $analysis = $service->analyze($ticket, $this->settings())['analysis'];
        Storage::disk('local')->put($attachment->path, $this->png(32, 32));
        $this->assertFalse($service->sourceStillMatches($ticket, $followup, $analysis));
        $this->assertSame('image_context_changed', $service->analyze($ticket, $this->settings())['analysis']['reason']);
        $attachment->delete();
        $this->assertFalse($service->sourceStillMatches($ticket, $followup, $analysis));
    }

    public function test_old_unknown_held_discarded_and_foreign_scope_results_cannot_be_reused(): void
    {
        foreach (['legacy', 'held', 'discarded', 'scope', 'unsafe_observations'] as $change) {
            [$ticket, , , $service, $analysis, $stored] = $this->rememberedFixture();
            if ($change === 'legacy') unset($analysis['analysis_version']);
            if ($change === 'held') $analysis['status'] = 'held';
            if ($change === 'discarded') $stored->status = 'discarded';
            if ($change === 'scope') $stored->site_id = 2;
            if ($change === 'unsafe_observations') $analysis['observations']['error_text'] = 'password=private-secret';
            $stored->image_analysis = $analysis; $stored->save();
            $this->followup($ticket);
            $result = $service->analyze($ticket, $this->settings());
            $this->assertSame('held', $result['analysis']['status'], $change);
            $this->assertSame([], $result['analysis']['observations']);
            $this->assertStringNotContainsString('private-secret', json_encode($result));
        }
    }

    public function test_a_newer_held_analysis_is_not_bypassed_using_an_older_safe_result(): void
    {
        [$ticket, , , $service, $analysis] = $this->rememberedFixture();
        TicketAiSuggestion::create(['ticket_id' => $ticket->id, 'status' => 'generated', 'image_analysis' => array_replace($analysis, ['status' => 'held'])]);
        $this->followup($ticket);
        $this->assertSame('image_context_unavailable', $service->analyze($ticket, $this->settings())['analysis']['reason']);
    }

    public function test_a_removed_newer_image_does_not_fall_back_to_an_older_picture(): void
    {
        [$ticket, , $attachment, $service, $analysis] = $this->rememberedFixture();
        $newImage = $this->followup($ticket, '这是最新截图');
        TicketAiSuggestion::create(['ticket_id' => $ticket->id, 'status' => 'generated',
            'image_analysis' => array_replace($analysis, ['source_message_id' => (int) $newImage->id])]);
        $attachment->delete();
        $this->followup($ticket);
        $this->assertSame('image_context_changed', $service->analyze($ticket, $this->settings())['analysis']['reason']);
    }

    public function test_a_deleted_current_image_is_not_treated_as_a_text_followup_to_an_older_image(): void
    {
        [$ticket, , , $service, $analysis] = $this->rememberedFixture();
        $latest = $this->followup($ticket, '这是新截图');
        TicketAiSuggestion::create(['ticket_id' => $ticket->id, 'status' => 'generated',
            'image_analysis' => array_replace($analysis, ['source_message_id' => (int) $latest->id])]);
        $this->assertSame('image_context_changed', $service->analyze($ticket, $this->settings())['analysis']['reason']);
    }

    public function test_a_reused_result_never_renews_the_original_one_hour_expiry(): void
    {
        try {
            \Carbon\Carbon::setTestNow('2026-11-03 12:00:00');
            [$ticket, , , $service] = $this->rememberedFixture();
            \Carbon\Carbon::setTestNow('2026-11-03 12:30:00');
            $this->followup($ticket);
            $first = $service->analyze($ticket, $this->settings())['analysis'];
            $this->assertSame('ready', $first['status']);
            TicketAiSuggestion::create(['ticket_id' => $ticket->id, 'status' => 'generated', 'image_analysis' => $first]);
            \Carbon\Carbon::setTestNow('2026-11-03 13:00:00');
            $this->followup($ticket);
            $this->assertSame('image_context_expired', $service->analyze($ticket, $this->settings())['analysis']['reason']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_new_current_images_take_precedence_over_old_context(): void
    {
        [$ticket, , $attachment, $service] = $this->rememberedFixture();
        $source = $this->followup($ticket);
        foreach (range(1, 4) as $index) TicketMessageAttachment::create(array_replace($attachment->getAttributes(), [
            'id' => null, 'ticket_message_id' => $source->id, 'path' => "ticket_attachments/{$ticket->id}/{$source->id}/{$index}.png",
        ]));
        $analysis = $service->analyze($ticket, $this->settings())['analysis'];
        $this->assertSame('image_limit', $analysis['reason']);
        $this->assertFalse($analysis['context_reused']);
    }

    private function rememberedFixture(): array
    {
        [$ticket, $source, $attachment] = $this->fixture();
        $provider = $this->createMock(TicketAiProviderClient::class);
        $provider->expects($this->once())->method('complete')->willReturn($this->completion($this->observations()));
        $service = $this->verifiedService($provider);
        $analysis = $service->analyze($ticket, $this->settings())['analysis'];
        $stored = TicketAiSuggestion::create(['ticket_id' => $ticket->id, 'status' => 'generated', 'image_analysis' => $analysis]);
        return [$ticket, $source, $attachment, $service, $analysis, $stored];
    }

    private function followup(Ticket $ticket, string $message = '还是刚才截图里的错误'): TicketMessage
    {
        return TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'message' => $message]);
    }

    private function fixture(?string $bytes = null): array
    {
        $ticket = Ticket::create(['user_id' => 10]);
        $source = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 10, 'message' => '请看截图']);
        $path = "ticket_attachments/{$ticket->id}/{$source->id}/example.png";
        Storage::disk('local')->put($path, $bytes ?? $this->png());
        $attachment = TicketMessageAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $source->id,
            'user_id' => 10, 'disk' => 'local', 'path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes ?? '')]);
        return [$ticket, $source, $attachment];
    }

    private function settings(): array
    {
        return ['vision_enable' => true, 'base_url' => 'https://ai.example.test/v1', 'model' => 'test-vision', 'api_key' => 'synthetic-key'];
    }

    private function verifiedService(TicketAiProviderClient $provider): TicketAiVisionService
    {
        $service = $this->createPartialMock(TicketAiVisionService::class, ['verifySupport']);
        $constructor = new \ReflectionMethod(TicketAiVisionService::class, '__construct');
        $constructor->invoke($service, $provider);
        $service->method('verifySupport')->willReturn(['supported' => true, 'completions' => []]);
        return $service;
    }

    private function observations(): array
    {
        return ['readable' => true, 'confidence' => 0.99, 'sensitive' => false, 'needs_human' => false,
            'problem_type' => 'client_connection', 'observations' => ['client' => 'v2rayN', 'app_version' => '7.25.6',
                'core' => 'sing-box', 'core_version' => '1.14.0', 'protocol' => 'Trojan', 'error_text' => 'TLS handshake failed']];
    }

    private function completion(array $decoded): array
    {
        return ['content' => json_encode($decoded, JSON_UNESCAPED_UNICODE), 'latency_ms' => 10,
            'input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30, 'prompt_chars' => 100, 'response_chars' => 50];
    }

    private function png(int $width = 16, int $height = 16): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function readProbeCode(string $url): string
    {
        $image = imagecreatefromstring(base64_decode(explode(',', $url, 2)[1], true));
        $code = '';
        for ($index = 0; $index < 6; $index++) {
            foreach (str_split('0123456789ABCDEF') as $character) {
                $glyph = imagecreatetruecolor(9, 15);
                imagefill($glyph, 0, 0, imagecolorallocate($glyph, 255, 255, 255));
                imagestring($glyph, 5, 0, 0, $character, imagecolorallocate($glyph, 0, 0, 0));
                $match = true;
                for ($x = 0; $x < 9 && $match; $x++) {
                    for ($y = 0; $y < 15; $y++) {
                        if (imagecolorat($glyph, $x, $y) !== imagecolorat($image, 75 + $index * 9 + $x, 22 + $y)) {
                            $match = false;
                            break;
                        }
                    }
                }
                imagedestroy($glyph);
                if ($match) { $code .= $character; break; }
            }
        }
        imagedestroy($image);
        return $code;
    }
}
