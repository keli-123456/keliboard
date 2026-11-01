<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\TicketAiProviderException;
use App\Models\Knowledge;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\TicketAiRequestLog;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketAiAssistantService;
use App\Services\TicketAiContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiAssistantV2Test extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('encrypter', new Encrypter(str_repeat('b', 32), 'AES-256-CBC'));
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createTicketTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createKnowledgeTable();
        $this->createSuggestionTable();
        $this->createRequestLogTable();
        $this->createSubscriptionControlEventTable();
    }

    public function test_capabilities_distinguish_disabled_incomplete_and_ready_states(): void
    {
        $this->bindTestSettings(['ticket_ai_enable' => false]);
        $disabled = (new TicketAiAssistantService())->capabilities();
        $this->assertFalse($disabled['available']);
        $this->assertSame('disabled', $disabled['reason']);

        $this->bindTestSettings([
            'ticket_ai_enable' => true,
            'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model',
        ]);
        $missingKey = (new TicketAiAssistantService())->capabilities();
        $this->assertFalse($missingKey['configured']);
        $this->assertSame('missing_api_key', $missingKey['reason']);

        $this->bindReadySettings();
        $ready = (new TicketAiAssistantService())->capabilities();
        $this->assertTrue($ready['enabled']);
        $this->assertTrue($ready['configured']);
        $this->assertTrue($ready['available']);
        $this->assertNull($ready['reason']);
    }

    public function test_suggestion_records_site_scope_and_never_sends_private_identifiers(): void
    {
        $this->bindReadySettings();
        [$ticket, $user, $site] = $this->siteTicket();
        $this->assertSame((int) $site->id, (int) $ticket->site_id, 'Ticket site_id was not persisted.');
        $this->assertNotNull($ticket->site, 'Ticket site relation could not resolve the persisted site.');
        $context = (new TicketAiContextService())->build($ticket, 12, null);
        $this->assertSame('site', $context['scope']['type'], 'Context boundary lost the ticket site before the provider call.');
        $this->assertSame((int) $site->id, $context['scope']['site_id']);
        Http::fake(['*' => Http::response($this->providerResponse(json_encode([
            'summary' => '订阅导入失败',
            'category' => '订阅与节点',
            'sentiment' => '焦急',
            'risk' => 'low',
            'risk_explanation' => '近期没有发现可核验的风控事件。',
            'needs_human' => false,
            'confidence' => 0.8,
            'draft' => '请重新导入订阅。',
            'knowledge_refs' => [],
        ], JSON_UNESCAPED_UNICODE), 20, 10))]);

        $result = (new TicketAiAssistantService())->suggest($ticket, '不要暴露 private@example.test', 88);

        $suggestion = TicketAiSuggestion::query()->findOrFail($result['suggestion_id']);
        $log = TicketAiRequestLog::query()->firstOrFail();
        $this->assertSame('site', $suggestion->scope_type);
        $this->assertSame((int) $site->id, $suggestion->site_id);
        $this->assertTrue($suggestion->structured_output);
        $this->assertSame(TicketAiRequestLog::STATUS_SUCCESS, $log->status);
        $this->assertSame((int) $site->id, $log->site_id);
        $this->assertSame(30, $log->total_tokens);
        $this->assertSame('site', $result['scope']['type']);
        $this->assertSame('近期没有发现可核验的风控事件。', $result['risk_explanation']);

        Http::assertSent(function ($request) use ($user): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($payload, '秒速云 AI')
                && str_contains($payload, 'risk_explanation_field')
                && !str_contains($payload, 'Keli 面板')
                && !str_contains($payload, $user->email)
                && !str_contains($payload, $user->token)
                && !str_contains($payload, $user->uuid);
        });
    }

    public function test_plain_text_fallback_requires_human_review_and_is_marked_unstructured(): void
    {
        $this->bindReadySettings();
        [$ticket] = $this->siteTicket();
        Http::fake(['*' => Http::response($this->providerResponse('建议用户重新导入订阅。'))]);

        $result = (new TicketAiAssistantService())->suggest($ticket, null, 7);
        $suggestion = TicketAiSuggestion::query()->findOrFail($result['suggestion_id']);

        $this->assertTrue($result['needs_human']);
        $this->assertFalse($result['structured_output']);
        $this->assertFalse($suggestion->structured_output);
        $this->assertSame('建议用户重新导入订阅。', $result['draft']);
    }

    public function test_risk_question_draft_includes_verified_evidence_when_model_omits_it(): void
    {
        $this->bindReadySettings();
        [$ticket, $user] = $this->siteTicket();
        $ticket->forceFill(['subject' => '为什么我的订阅被风控重置'])->save();
        DB::table('v2_subscription_control_event')->insert([
            'event_id' => 'risk-ticket-1',
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => 'multi_region_pull',
            'reason' => 'internal reason must not be copied',
            'action' => 'reset_token_uuid',
            'client_ip' => '203.0.113.88',
            'regions' => json_encode(['SG', 'JP', 'US']),
            'risk_score' => 72,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Http::fake([
            '*' => Http::response($this->providerResponse(json_encode([
                'summary' => '用户询问风控原因',
                'category' => '订阅与节点',
                'sentiment' => '疑问',
                'risk' => 'medium',
                'needs_human' => false,
                'confidence' => 0.8,
                'draft' => '您好，我们已经查询您的账号。',
                'knowledge_refs' => [],
            ], JSON_UNESCAPED_UNICODE))),
        ]);

        $result = (new TicketAiAssistantService())->suggest($ticket, null, 7);

        $this->assertStringContainsString('经查询，近期风控依据如下：', $result['draft']);
        $this->assertStringContainsString('短时从多个地区拉取订阅', $result['draft']);
        $this->assertStringContainsString('近期触发 1 次', $result['draft']);
        $this->assertStringContainsString('已重置订阅凭证', $result['draft']);
        $this->assertStringNotContainsString('203.0.113.88', $result['draft']);
        $this->assertSame('multi_region_pull', $result['risk_evidence'][0]['code']);
    }

    public function test_provider_failure_creates_sanitized_failure_log(): void
    {
        $this->bindReadySettings();
        [$ticket, , $site] = $this->siteTicket();
        Http::fake(['*' => Http::response(['secret' => 'do-not-log'], 429)]);

        try {
            (new TicketAiAssistantService())->suggest($ticket, null, 7);
            $this->fail('Expected provider exception.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame('rate_limited', $exception->errorCode());
        }

        $log = TicketAiRequestLog::query()->firstOrFail();
        $this->assertSame(TicketAiRequestLog::STATUS_FAILED, $log->status);
        $this->assertSame('rate_limited', $log->error_code);
        $this->assertSame((int) $site->id, $log->site_id);
        $this->assertSame('ai.example.test', $log->provider_host);
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_request_log', 'response_body'));
        $stats = (new TicketAiAssistantService())->stats(7);
        $this->assertCount(1, $stats['scope_breakdown']);
        $this->assertSame('站点 · 秒速云', $stats['scope_breakdown'][0]['label']);
        $this->assertSame(0, $stats['scope_breakdown'][0]['generated']);
        $this->assertSame(1, $stats['scope_breakdown'][0]['requests']);
    }

    public function test_connection_test_and_extended_stats_expose_only_operational_metrics(): void
    {
        $this->bindReadySettings();
        app(\App\Support\Setting::class)->save([
            'ticket_ai_input_price_per_million' => 10,
            'ticket_ai_output_price_per_million' => 20,
        ]);
        Http::fake(['*' => Http::response($this->providerResponse('{"ping":"ok"}', 4, 2))]);

        $connection = (new TicketAiAssistantService())->testConnection(5);
        $this->assertTrue($connection['ok']);
        $this->assertSame('test-model', $connection['model']);

        TicketAiRequestLog::record([
            'status' => TicketAiRequestLog::STATUS_FAILED,
            'error_code' => 'timeout',
            'latency_ms' => 500,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ]);
        $stats = (new TicketAiAssistantService())->stats(7);

        $this->assertSame(2, $stats['requests']);
        $this->assertSame(0.5, $stats['success_rate']);
        $this->assertSame(6, $stats['total_tokens']);
        $this->assertEqualsWithDelta(0.00008, $stats['estimated_cost'], 0.000001);
        $this->assertSame('CNY', $stats['estimated_cost_currency']);
        $this->assertSame('timeout', $stats['top_errors'][0]['error_code']);
    }

    public function test_knowledge_and_references_are_limited_to_the_ticket_site(): void
    {
        $this->bindReadySettings();
        app(\App\Support\Setting::class)->save(['ticket_ai_knowledge_enable' => true]);
        [$ticket, , $site] = $this->siteTicket();
        $otherSite = Site::query()->create([
            'code' => 'other-' . uniqid(),
            'name' => '其他站点',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $global = Knowledge::query()->create([
            'title' => '全局订阅排查',
            'category' => '订阅',
            'body' => '订阅导入失败时重新导入。',
            'show' => true,
            'scope_type' => Knowledge::SCOPE_GLOBAL,
        ]);
        $current = Knowledge::query()->create([
            'title' => '秒速云订阅排查',
            'category' => '订阅',
            'body' => '秒速云订阅导入失败处理。',
            'show' => true,
            'scope_type' => Knowledge::SCOPE_SITE,
            'site_id' => $site->id,
        ]);
        $other = Knowledge::query()->create([
            'title' => '其他站点订阅排查',
            'category' => '订阅',
            'body' => '其他站点专用订阅导入失败处理。',
            'show' => true,
            'scope_type' => Knowledge::SCOPE_SITE,
            'site_id' => $otherSite->id,
        ]);
        $platform = Knowledge::query()->create([
            'title' => '主站订阅排查',
            'category' => '订阅',
            'body' => '仅主站处理订阅导入失败。',
            'show' => true,
            'scope_type' => Knowledge::SCOPE_PLATFORM,
        ]);
        Http::fake(['*' => Http::response($this->providerResponse(json_encode([
            'draft' => '请重新导入订阅。',
            'category' => '订阅与节点',
            'risk' => 'low',
            'knowledge_refs' => [$global->id, $current->id, $other->id, $platform->id, 999999],
        ], JSON_UNESCAPED_UNICODE)))]);

        $result = (new TicketAiAssistantService())->suggest($ticket, null, 8);

        $this->assertSame([(int) $global->id, (int) $current->id], $result['knowledge_refs']);
        $this->assertSame(
            ['全局订阅排查', '秒速云订阅排查'],
            array_column($result['matched_knowledge'], 'title')
        );
        Http::assertSent(function ($request) use ($other, $platform): bool {
            $messages = $request->data()['messages'];
            $content = (string) ($messages[array_key_last($messages)]['content'] ?? '');

            return !str_contains($content, (string) $other->title)
                && !str_contains($content, (string) $platform->title);
        });
    }

    public function test_knowledge_search_finds_late_articles_and_relevant_passages_with_source_versions(): void
    {
        $this->bindReadySettings();
        app(\App\Support\Setting::class)->save(['ticket_ai_knowledge_enable' => true]);
        Schema::table('v2_knowledge', function (Blueprint $table): void {
            $table->string('source_version')->nullable();
        });
        [$ticket] = $this->siteTicket();
        $ticket->subject = 'TrustTunnel 自签指纹配置';
        $ticket->save();
        foreach (range(1, 85) as $number) {
            Knowledge::query()->create([
                'title' => '普通资料' . $number, 'category' => '常见问题', 'body' => '注册与购买介绍',
                'show' => true, 'scope_type' => Knowledge::SCOPE_GLOBAL,
            ]);
        }
        $body = '<p>' . str_repeat('这里是一般使用说明。', 200) . '</p>'
            . '<h2>TrustTunnel 自签证书</h2><div>订阅中的指纹需要与节点证书匹配，不能关闭证书验证。</div>';
        $article = Knowledge::query()->create([
            'title' => 'TrustTunnel 配置指引', 'category' => '协议', 'body' => $body,
            'show' => true, 'scope_type' => Knowledge::SCOPE_GLOBAL, 'source_version' => '2026.11.01',
        ]);
        Http::fake(['*' => Http::response($this->providerResponse(json_encode([
            'draft' => '请核对订阅里的指纹。', 'category' => '客户端连接', 'risk' => 'low',
            'knowledge_refs' => [$article->id],
        ], JSON_UNESCAPED_UNICODE)))]);

        $result = (new TicketAiAssistantService())->suggest($ticket, null, 8);

        $this->assertSame([(int) $article->id], $result['knowledge_refs']);
        $reference = $result['matched_knowledge'][0];
        $this->assertSame('2026.11.01', $reference['source_version']);
        $this->assertSame(hash('sha256', $body), $reference['content_hash']);
        $this->assertSame($result['matched_knowledge'], TicketAiSuggestion::findOrFail($result['suggestion_id'])->matched_knowledge);
        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'];
            $content = (string) $messages[array_key_last($messages)]['content'];
            return str_contains($content, '不能关闭证书验证') && str_contains($content, '2026.11.01');
        });
    }

    public function test_passages_keep_html_entities_inline_text_and_a_bounded_excerpt(): void
    {
        $method = new \ReflectionMethod(TicketAiAssistantService::class, 'knowledgePassage');
        $html = '<h2>客户端</h2><p>普通介绍</p><div>sing-box <strong>TLS</strong> 指纹 &amp; 证书核验</div>'
            . '<script>untrusted-script-only</script><p>' . str_repeat('无关说明。', 1000) . '</p>';
        $passage = $method->invoke(new TicketAiAssistantService(), $html, ['sing-box', 'tls', '指纹']);
        $this->assertStringContainsString('sing-box TLS 指纹 & 证书核验', $passage);
        $this->assertStringNotContainsString('untrusted-script-only', $passage);
        $this->assertLessThanOrEqual(1200, mb_strlen($passage));
    }

    public function test_sensitive_subject_also_blocks_an_automated_clarification(): void
    {
        $this->bindReadySettings();
        [$ticket] = $this->siteTicket();
        $ticket->subject = '申请退款';
        $ticket->save();
        $result = (new TicketAiAssistantService())->suggestClarification($ticket, '请补充客户端版本');
        $this->assertTrue($result['needs_human']);
        $this->assertFalse($result['system_grounded']);
    }

    public function test_fixed_clarification_keeps_its_grounded_exemption_without_knowledge(): void
    {
        $this->bindReadySettings();
        [$ticket] = $this->siteTicket();
        $ticket->messages()->update(['message' => '订阅导入失败，请帮忙排查']);
        $ticket->unsetRelation('messages');
        Http::fake();
        $result = (new TicketAiAssistantService())->suggestClarification($ticket, '请补充客户端与内核版本');
        $this->assertTrue($result['system_grounded']);
        $this->assertFalse($result['needs_human']);
        $this->assertSame('请补充客户端与内核版本', $result['draft']);
        $this->assertNull((new \App\Services\TicketAiAutoReplyService())->rejectionReason($result));
        Http::assertNothingSent();
    }

    public function test_incomplete_or_malformed_model_safety_fields_are_not_treated_as_approval(): void
    {
        $method = new \ReflectionMethod(TicketAiAssistantService::class, 'normalizeAiResult');
        $safe = ['draft' => '普通指引', 'category' => '客户端连接', 'risk' => 'low', 'needs_human' => false, 'confidence' => 0.95];
        $service = new TicketAiAssistantService();
        $this->assertFalse($method->invoke($service, '', [], $safe)['needs_human']);
        foreach ([null, '0.99', 1.5, -0.1, INF, NAN] as $confidence) {
            $result = $method->invoke($service, '', [], array_merge($safe, ['confidence' => $confidence]));
            $this->assertSame(0.0, $result['confidence']);
        }
        foreach ([null, 'false', 0] as $needsHuman) {
            $result = $method->invoke($service, '', [], array_merge($safe, ['needs_human' => $needsHuman]));
            $this->assertTrue($result['needs_human']);
        }
        $result = $method->invoke($service, '', [], array_merge($safe, ['risk' => 'unknown']));
        $this->assertSame('medium', $result['risk']);
    }

    public function test_screenshot_observations_drive_scoped_knowledge_search_without_forwarding_images_to_the_drafting_step(): void
    {
        $this->bindReadySettings();
        app(\App\Support\Setting::class)->save(['ticket_ai_vision_enable' => true, 'ticket_ai_knowledge_enable' => true]);
        [$ticket] = $this->siteTicket();
        $this->addScreenshot($ticket);
        $article = Knowledge::create(['title' => 'sing-box TLS 排查', 'category' => '客户端',
            'body' => '<p>sing-box TLS handshake failed 时核对内核版本与证书。</p>', 'show' => true, 'scope_type' => 'global']);
        $vision = $this->createPartialMock(\App\Services\TicketAiVisionService::class, ['verifySupport']);
        (new \ReflectionMethod(\App\Services\TicketAiVisionService::class, '__construct'))->invoke($vision);
        $vision->method('verifySupport')->willReturn(['supported' => true, 'completions' => []]);
        $imageCalls = 0;
        Http::fake(function ($request) use ($article, &$imageCalls) {
            $messages = $request->data()['messages'];
            if (is_array($messages[array_key_last($messages)]['content'])) {
                $imageCalls++;
                return Http::response($this->providerResponse(json_encode([
                    'readable' => true, 'confidence' => 0.98, 'sensitive' => false, 'needs_human' => false,
                    'problem_type' => 'client_connection', 'observations' => ['client' => 'v2rayN', 'core' => 'sing-box', 'error_text' => 'TLS handshake failed'],
                ])));
            }
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('sing-box TLS 排查', $payload);
            $this->assertStringContainsString('TLS handshake failed', $payload);
            $this->assertStringContainsString('untrusted_reference_data', $payload);
            $this->assertStringNotContainsString('data:image', $payload);
            return Http::response($this->providerResponse(json_encode(['draft' => '请按 sing-box 教程核对内核与证书。',
                'category' => '客户端连接', 'risk' => 'low', 'needs_human' => false, 'confidence' => 0.97, 'knowledge_refs' => [$article->id]], JSON_UNESCAPED_UNICODE)));
        });

        $result = (new TicketAiAssistantService(vision: $vision))->suggest($ticket);
        $this->assertSame(1, $imageCalls);
        $this->assertSame('ready', $result['image_analysis']['status']);
        $this->assertFalse($result['needs_human']);
        $this->assertSame([(int) $article->id], $result['knowledge_refs']);
        $this->assertSame($result['image_analysis'], TicketAiSuggestion::findOrFail($result['suggestion_id'])->image_analysis);
        $this->assertSame(2, TicketAiRequestLog::count());
        $this->assertStringNotContainsString('data:image', TicketAiRequestLog::all()->toJson());
        $this->assertStringNotContainsString('data:image', TicketAiSuggestion::all()->toJson());
    }

    public function test_held_screenshot_never_calls_the_drafting_model_and_remains_reviewable(): void
    {
        $this->bindReadySettings();
        app(\App\Support\Setting::class)->save(['ticket_ai_vision_enable' => true]);
        [$ticket] = $this->siteTicket();
        $this->addScreenshot($ticket);
        $vision = $this->createPartialMock(\App\Services\TicketAiVisionService::class, ['verifySupport']);
        (new \ReflectionMethod(\App\Services\TicketAiVisionService::class, '__construct'))->invoke($vision);
        $vision->method('verifySupport')->willReturn(['supported' => false, 'completions' => []]);
        Http::fake();
        $result = (new TicketAiAssistantService(vision: $vision))->suggest($ticket);
        $this->assertTrue($result['needs_human']);
        $this->assertSame('held', $result['image_analysis']['status']);
        $this->assertFalse($result['system_grounded']);
        $this->assertSame('generated', TicketAiSuggestion::findOrFail($result['suggestion_id'])->status);
        Http::assertNothingSent();
    }

    private function addScreenshot(Ticket $ticket): void
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD required.');
        (require base_path('database/migrations/2026_01_01_000001_create_v2_ticket_message_attachment_table.php'))->up();
        (require base_path('database/migrations/2026_11_01_000001_add_image_analysis_to_ticket_ai_suggestions.php'))->up();
        Storage::fake('local');
        $ticket->subject = '请看截图';
        $ticket->save();
        $ticket->messages()->update(['message' => '请看图']);
        $ticket->unsetRelation('messages');
        $source = $ticket->messages()->firstOrFail();
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        $path = "ticket_attachments/{$ticket->id}/{$source->id}/test.png";
        Storage::disk('local')->put($path, $bytes);
        \App\Models\TicketMessageAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $source->id,
            'user_id' => $ticket->user_id, 'disk' => 'local', 'path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes)]);
    }

    public function test_regeneration_supersedes_old_draft_and_scope_stats_use_the_active_draft(): void
    {
        $this->bindReadySettings();
        [$ticket] = $this->siteTicket();
        Http::fake(['*' => Http::sequence()
            ->push($this->providerResponse('{"draft":"第一次草稿","risk":"low"}', 4, 2))
            ->push($this->providerResponse('{"draft":"第二次草稿","risk":"low"}', 4, 2))]);
        $service = new TicketAiAssistantService();

        $first = $service->suggest($ticket, null, 9);
        $second = $service->suggest($ticket, null, 9);
        $stats = $service->stats(7);

        $this->assertSame(
            TicketAiSuggestion::STATUS_SUPERSEDED,
            TicketAiSuggestion::query()->findOrFail($first['suggestion_id'])->status
        );
        $this->assertSame(
            TicketAiSuggestion::STATUS_GENERATED,
            TicketAiSuggestion::query()->findOrFail($second['suggestion_id'])->status
        );
        $this->assertSame(1, $stats['generated']);
        $this->assertSame('站点 · 秒速云', $stats['scope_breakdown'][0]['label']);
        $this->assertSame(1, $stats['scope_breakdown'][0]['generated']);
        $this->assertSame(2, $stats['scope_breakdown'][0]['requests']);
        $this->assertSame(12, $stats['scope_breakdown'][0]['total_tokens']);

        try {
            $service->recordFeedback($first['suggestion_id'], (int) $ticket->id, 9, TicketAiSuggestion::STATUS_INSERTED);
            $this->fail('Expected a superseded draft to remain terminal.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('替代', $exception->getMessage());
        }
    }

    private function bindReadySettings(): void
    {
        $this->bindTestSettings([
            'app_name' => 'Platform Cloud',
            'app_url' => 'https://platform.example.test',
            'ticket_ai_enable' => true,
            'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model',
            'ticket_ai_api_key' => Crypt::encryptString('sk-test'),
            'ticket_ai_temperature' => 0.2,
            'ticket_ai_max_messages' => 12,
            'ticket_ai_max_tokens' => 800,
            'ticket_ai_timeout' => 30,
            'ticket_ai_json_mode' => false,
            'ticket_ai_knowledge_enable' => false,
        ]);
    }

    /** @return array{Ticket,User,Site} */
    private function siteTicket(): array
    {
        $site = Site::query()->create([
            'code' => 'miaosu-' . uniqid(),
            'name' => '秒速云',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => '秒速云 AI',
            'enabled' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'miaosu.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $user = User::query()->create([
            'site_id' => $site->id,
            'email' => 'private@example.test',
            'password' => 'password',
            'token' => 'abcdef0123456789abcdef0123456789',
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'transfer_enable' => 1000,
            'u' => 100,
            'd' => 50,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket = Ticket::query()->create([
            'site_id' => $site->id,
            'user_id' => $user->id,
            'subject' => '订阅导入失败',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => '邮箱 private@example.test token=abcdef0123456789abcdef0123456789',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return [$ticket, $user, $site];
    }

    private function providerResponse(string $content, int $inputTokens = 0, int $outputTokens = 0): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    private function createPlanTable(): void
    {
        Schema::create('v2_plan', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createSubscriptionControlEventTable(): void
    {
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('code')->nullable();
            $table->text('reason')->nullable();
            $table->string('action')->nullable();
            $table->string('client_ip')->nullable();
            $table->text('ua_categories')->nullable();
            $table->text('regions')->nullable();
            $table->integer('online_ip_count')->nullable();
            $table->integer('source_user_count')->nullable();
            $table->integer('ip_count')->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('hit_count')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createOrderTable(): void
    {
        Schema::create('v2_order', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->string('period')->nullable();
            $table->integer('total_amount')->default(0);
            $table->integer('type')->default(0);
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createKnowledgeTable(): void
    {
        Schema::create('v2_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('category')->nullable();
            $table->text('body')->nullable();
            $table->boolean('show')->default(true);
            $table->string('scope_type')->default(Knowledge::SCOPE_GLOBAL);
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createSuggestionTable(): void
    {
        Schema::create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->integer('admin_id')->nullable();
            $table->string('model')->nullable();
            $table->string('scope_type')->default('platform');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->boolean('structured_output')->default(true);
            $table->string('category')->nullable();
            $table->string('sentiment')->nullable();
            $table->string('risk')->nullable();
            $table->boolean('needs_human')->default(false);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->text('summary')->nullable();
            $table->mediumText('draft')->nullable();
            $table->string('draft_hash', 64)->nullable();
            $table->text('instruction')->nullable();
            $table->json('knowledge_refs')->nullable();
            $table->json('matched_knowledge')->nullable();
            $table->string('status')->default('generated');
            $table->integer('inserted_at')->nullable();
            $table->integer('discarded_at')->nullable();
            $table->integer('sent_at')->nullable();
            $table->integer('reply_message_id')->nullable();
            $table->string('final_message_hash', 64)->nullable();
            $table->boolean('edited')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createRequestLogTable(): void
    {
        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id')->nullable();
            $table->integer('suggestion_id')->nullable();
            $table->integer('admin_id')->nullable();
            $table->string('status', 20);
            $table->string('error_code', 40)->nullable();
            $table->string('scope_type', 20)->default('platform');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->string('provider_host')->nullable();
            $table->string('model')->nullable();
            $table->integer('latency_ms')->default(0);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('prompt_chars')->default(0);
            $table->integer('response_chars')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}
