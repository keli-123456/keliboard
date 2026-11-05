<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Knowledge;
use App\Models\Setting as SettingModel;
use App\Models\Ticket;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketAiAssistantService;
use App\Services\TicketAiAutoReplyService;
use App\Services\TicketAiContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiAssistantServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        app()->instance('encrypter', new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));

        $this->createUserTable();
        $this->createTicketTablesForAi();
        $this->createTicketAiSuggestionTable();
        $this->createKnowledgeTable();
        $this->createSettingsTable();
    }

    public function test_public_settings_hide_api_key_and_prepare_save_encrypts_secret(): void
    {
        $service = new TicketAiAssistantService();
        $prepared = $service->prepareSettingsForSave([
            'ticket_ai_enable' => true,
            'ticket_ai_api_key' => 'sk-test',
        ]);

        $this->assertArrayHasKey('ticket_ai_api_key', $prepared);
        $this->assertNotSame('sk-test', $prepared['ticket_ai_api_key']);
        $this->assertSame('sk-test', Crypt::decryptString($prepared['ticket_ai_api_key']));

        SettingModel::createOrUpdate('ticket_ai_api_key', $prepared['ticket_ai_api_key']);
        $visible = $service->publicSettings();

        $this->assertSame('', $visible['ticket_ai_api_key']);
        $this->assertTrue($visible['ticket_ai_api_key_set']);
        $this->assertSame('ready', $visible['ticket_ai_api_key_status']);
        $this->assertSame('broad', $visible['ticket_ai_auto_reply_mode']);
    }

    public function test_invalid_encrypted_api_key_reports_decrypt_failure(): void
    {
        $this->saveSettings([
            'ticket_ai_enable' => true,
            'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model',
            'ticket_ai_api_key' => 'encrypted-with-another-app-key',
        ]);

        $service = new TicketAiAssistantService();
        $visible = $service->publicSettings();
        $capabilities = $service->capabilities();

        $this->assertFalse($visible['ticket_ai_api_key_set']);
        $this->assertSame('decrypt_failed', $visible['ticket_ai_api_key_status']);
        $this->assertFalse($capabilities['configured']);
        $this->assertSame('api_key_decrypt_failed', $capabilities['reason']);
    }

    public function test_risk_probe_uses_luna_and_synthetic_content_without_loading_a_real_ticket(): void
    {
        $service = new TicketAiAssistantService();
        $this->saveSettings($service->prepareSettingsForSave([
            'ticket_ai_enable' => true, 'ticket_ai_model' => 'gpt-6.1-sol', 'ticket_ai_max_tokens' => 800,
            'ticket_ai_base_url' => 'https://api.openai.com/v1', 'ticket_ai_api_key' => 'sk-synthetic',
            'risk_ai_model' => 'gpt-6-luna', 'risk_ai_max_tokens' => 4096, 'risk_ai_reasoning_effort' => 'low',
        ]));
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ping":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);
        $result = $service->testRiskConnection();
        $this->assertTrue($result['ok']); $this->assertSame('gpt-6-luna', $result['model']);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-luna'
            && $request['max_completion_tokens'] === 4096 && $request['reasoning_effort'] === 'low'
            && count($request['messages']) === 2 && $request['messages'][1]['content'] === '{"ping":"ok"}');
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('sk-synthetic', json_encode($result));
        $this->assertSame('gpt-6.1-sol', $service->publicSettings()['ticket_ai_model']);
    }

    public function test_a_risk_model_override_cannot_bypass_the_shared_disabled_switch(): void
    {
        $this->saveSettings(['ticket_ai_enable' => false, 'risk_ai_model' => 'gpt-6-luna']);
        Http::fake();
        try {
            (new TicketAiAssistantService())->testRiskConnection();
            $this->fail('Disabled provider must not be contacted.');
        } catch (\App\Exceptions\TicketAiProviderException $error) {
            $this->assertSame('disabled', $error->errorCode());
        }
        Http::assertNothingSent();
    }

    public function test_suggest_generates_ticket_reply_draft_with_knowledge_context(): void
    {
        $user = User::create([
            'email' => 'user@example.test',
            'password' => 'secret',
            'token' => 'token',
            'uuid' => 'uuid',
            'plan_id' => 3,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Windows 客户端无法连接',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => 'Windows 客户端提示核心 API 未就绪，节点连接失败。',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Knowledge::create([
            'title' => 'Windows 客户端连接排查',
            'category' => '客户端',
            'body' => '请先确认客户端核心已启动，然后刷新订阅并重新测试节点。',
            'show' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->saveSettings([
            'ticket_ai_enable' => true,
            'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model',
            'ticket_ai_api_key' => Crypt::encryptString('sk-test'),
            'ticket_ai_knowledge_enable' => true,
        ]);

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => '用户 Windows 客户端连接失败',
                            'category' => '客户端连接',
                            'sentiment' => '焦急',
                            'risk' => 'low',
                            'needs_human' => false,
                            'confidence' => 0.86,
                            'draft' => '您好，请先确认客户端核心已启动，再刷新订阅并重新测试节点。',
                            'knowledge_refs' => [1],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $result = (new TicketAiAssistantService())->suggest($ticket, null, 99);

        $this->assertIsInt($result['suggestion_id']);
        $this->assertSame('客户端连接', $result['category']);
        $this->assertSame('您好，请先确认客户端核心已启动，再刷新订阅并重新测试节点。', $result['draft']);
        $this->assertFalse($result['needs_human']);
        $this->assertSame('Windows 客户端连接排查', $result['matched_knowledge'][0]['title']);
        $this->assertContains('客户端连接', $result['category_options']);

        $suggestion = TicketAiSuggestion::find($result['suggestion_id']);
        $this->assertNotNull($suggestion);
        $this->assertSame(99, (int) $suggestion->admin_id);
        $this->assertSame('generated', $suggestion->status);
        $this->assertSame('客户端连接', $suggestion->category);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $content = $payload['messages'][array_key_last($payload['messages'])]['content'] ?? '';
            $securityPrompt = $payload['messages'][0]['content'] ?? '';

            return $request->hasHeader('Authorization', 'Bearer sk-test')
                && $payload['model'] === 'test-model'
                && str_contains($securityPrompt, '不可信资料')
                && str_contains($content, 'Windows 客户端无法连接')
                && str_contains($content, 'Windows 客户端连接排查');
        });
    }

    public static function officialDraftCases(): array
    {
        return [
            'grounded' => [true, false, 200, [1], 'Check which local process owns the listening port.', 'cited', 2],
            'invented reference' => [true, false, 200, [2], 'Unsupported claim.', 'uncited', 2],
            'model supplied link' => [true, false, 200, [1], 'Go to https://untrusted.test/fix.', 'uncited', 2],
            'source unavailable' => [true, false, 503, [1], 'Unsupported claim.', 'no_match', 2],
            'sensitive' => [true, true, 200, [1], 'Needs account review.', null, 1],
            'disabled' => [false, false, 200, [1], 'Existing draft.', null, 1],
        ];
    }

    #[DataProvider('officialDraftCases')]
    public function test_official_lookup_is_a_draft_only_fallback(
        bool $enabled, bool $sensitive, int $status, array $refs, string $draft, ?string $expected, int $requests
    ): void {
        config(['tickets.ai_official_lookup' => $enabled]);
        $user = User::create(['email' => 'owner@example.test', 'password' => 'secret', 'token' => 'token', 'uuid' => 'uuid']);
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'Client error', 'level' => 1,
            'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN]);
        $context = $this->createMock(TicketAiContextService::class);
        $context->method('build')->willReturn(['scope' => ['type' => 'platform'],
            'conversation' => [['role' => 'assistant', 'content' => 'Please paste the error text.'],
                ['role' => 'user', 'content' => 'Windows: address already in use.']],
            'business' => ['requires_human' => $sensitive]]);
        $this->saveSettings([
            'ticket_ai_enable' => true, 'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model', 'ticket_ai_api_key' => Crypt::encryptString('sk-test'),
        ]);
        $url = TicketAiOfficialLookupTest::WINDOWS;
        Http::preventStrayRequests();
        Http::fake([
            $url => Http::response('<table><tr><td>WSAEADDRINUSE</td><td>Address already in use.</td></tr></table>', $status),
            'https://ai.example.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'category' => '客户端连接', 'risk' => 'low', 'needs_human' => false, 'confidence' => 0.99,
                'draft' => $draft, 'official_source_refs' => $refs,
            ])]]]]),
        ]);

        $result = (new TicketAiAssistantService($context))->suggest($ticket);
        $this->assertSame($expected, $result['official_lookup']['status'] ?? null);
        $this->assertSame($expected === 'cited', str_contains($result['draft'], $url));
        $this->assertStringNotContainsString('untrusted.test', $result['draft']);
        $suggestion = TicketAiSuggestion::findOrFail($result['suggestion_id']);
        $this->assertSame($result['draft'], $suggestion->draft);
        if ($expected !== null) {
            $this->assertTrue($suggestion->needs_human);
            $this->assertSame('needs_human', (new TicketAiAutoReplyService())->rejectionReason($result));
            $this->assertSame([], $result['matched_knowledge']);
        }
        Http::assertSentCount($requests);
        $this->assertSame(0, TicketMessage::count());
    }

    public function test_real_context_pipeline_supplies_private_safe_traffic_and_ticket_evidence_to_the_model(): void
    {
        $user = User::create([
            'email' => 'private-owner@example.test', 'password' => 'secret', 'token' => 'private-account-token',
            'uuid' => 'private-account-uuid', 'transfer_enable' => 100 * 1024 ** 3, 'u' => 0, 'd' => 60 * 1024 ** 3,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $ticket = Ticket::create([
            'user_id' => $user->id, 'subject' => '流量一下就没了', 'status' => Ticket::STATUS_OPENING,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => '前两个工单我也没有关啊',
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $previous = Ticket::create([
            'user_id' => $user->id, 'subject' => 'private previous ticket text', 'status' => Ticket::STATUS_CLOSED,
            'created_at' => time() - 1000, 'updated_at' => time() - 500,
        ]);
        Schema::create('v2_stat_user', function (Blueprint $table): void {
            $table->integer('user_id'); $table->integer('record_at'); $table->string('record_type');
            $table->bigInteger('u'); $table->bigInteger('d'); $table->integer('updated_at');
        });
        DB::table('v2_stat_user')->insert([
            'user_id' => $user->id, 'record_at' => \Carbon\Carbon::now(config('app.timezone'))->startOfDay()->timestamp,
            'record_type' => 'd', 'u' => 0, 'd' => 30 * 1024 ** 3, 'updated_at' => time(),
        ]);
        $this->saveSettings([
            'ticket_ai_enable' => true, 'ticket_ai_base_url' => 'https://ai.example.test/v1',
            'ticket_ai_model' => 'test-model', 'ticket_ai_api_key' => Crypt::encryptString('sk-synthetic'),
            'ticket_ai_knowledge_enable' => false,
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => '流量与工单核查', 'category' => '订阅与节点', 'risk' => 'low', 'needs_human' => false,
            'confidence' => 0.99, 'draft' => '无法查看流量记录；工单由您关闭，已经补偿999GB。',
        ], JSON_UNESCAPED_UNICODE)]]]])]);

        $result = (new TicketAiAssistantService())->suggest($ticket);

        $this->assertTrue($result['needs_human']);
        foreach (['剩余 40.00 GB', '30.00 GB', '历史工单 #' . $previous->id, '不能证明关闭操作人', '节点明细暂不可读取'] as $fact) {
            $this->assertStringContainsString($fact, $result['draft']);
        }
        foreach (['999GB', '由您关闭', '无法查看流量记录'] as $fiction) {
            $this->assertStringNotContainsString($fiction, $result['draft']);
        }
        Http::assertSent(function ($request) use ($user): bool {
            $messages = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);
            return str_contains($messages, 'backend_verified_read_only')
                && str_contains($messages, 'billed_bytes') && str_contains($messages, (string) (30 * 1024 ** 3))
                && str_contains($messages, '禁止再次相乘') && str_contains($messages, 'ticket_history')
                && !str_contains($messages, $user->email) && !str_contains($messages, $user->token)
                && !str_contains($messages, $user->uuid) && !str_contains($messages, 'private previous ticket text');
        });
        Http::assertSentCount(1);
        $this->assertSame(60 * 1024 ** 3, (int) $user->fresh()->d);
        $this->assertSame(1, TicketMessage::query()->count());
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame('generated', TicketAiSuggestion::find($result['suggestion_id'])->status);
    }

    public function test_chinese_natural_language_is_segmented_for_knowledge_matching(): void
    {
        $service = new TicketAiAssistantService();
        $keywordsMethod = new \ReflectionMethod($service, 'keywords');
        $scoreMethod = new \ReflectionMethod($service, 'knowledgeScore');

        $keywords = $keywordsMethod->invoke(
            $service,
            '我在手机上导入以后一直不能使用，应该怎样重新添加订阅？'
        );

        $this->assertContains('订阅', $keywords);
        $this->assertContains('导入', $keywords);
        $this->assertNotContains('不能', $keywords);

        $relevantScore = $scoreMethod->invoke(
            $service,
            '订阅导入、加速与重置',
            '使用指南',
            '复制订阅后重新导入客户端。',
            $keywords
        );
        $unrelatedScore = $scoreMethod->invoke(
            $service,
            '套餐、流量与续费说明',
            '使用指南',
            '查看套餐到期和剩余流量。',
            $keywords
        );

        $this->assertGreaterThan($unrelatedScore, $relevantScore);
    }

    public function test_prompt_context_formats_unix_timestamps_as_readable_local_time(): void
    {
        $service = new TicketAiAssistantService();
        $method = new \ReflectionMethod($service, 'formatTimestampsForPrompt');

        $formatted = $method->invoke($service, [
            'created_at' => 1787039339,
            'nested' => [
                'last_trigger_at' => 1787039339,
                'event_count' => 1787039339,
            ],
        ]);

        $this->assertSame('2026-08-18 15:48:59', $formatted['created_at']);
        $this->assertSame('2026-08-18 15:48:59', $formatted['nested']['last_trigger_at']);
        $this->assertSame(1787039339, $formatted['nested']['event_count']);
    }

    public function test_feedback_and_sent_state_track_ai_draft_adoption(): void
    {
        $user = User::create([
            'email' => 'user@example.test',
            'password' => 'secret',
            'token' => 'token',
            'uuid' => 'uuid',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => '支付不到账',
            'level' => 2,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $suggestion = TicketAiSuggestion::create([
            'ticket_id' => $ticket->id,
            'admin_id' => 42,
            'model' => 'test-model',
            'category' => '支付退款',
            'risk' => 'high',
            'needs_human' => true,
            'confidence' => 0.8,
            'summary' => '用户支付后未到账',
            'draft' => '您好，我们会先核查支付记录。',
            'draft_hash' => hash('sha256', '您好，我们会先核查支付记录。'),
            'status' => TicketAiSuggestion::STATUS_GENERATED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = new TicketAiAssistantService();
        $feedback = $service->recordFeedback((int) $suggestion->id, (int) $ticket->id, 42, 'inserted');
        $this->assertSame('inserted', $feedback['status']);

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => 42,
            'message' => '您好，我们会先核查支付记录，请同时提供订单号。',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $service->markSent(
            (int) $suggestion->id,
            (int) $ticket->id,
            42,
            $message,
            '您好，我们会先核查支付记录，请同时提供订单号。'
        );

        $suggestion->refresh();
        $this->assertSame(TicketAiSuggestion::STATUS_SENT, $suggestion->status);
        $this->assertSame((int) $message->id, (int) $suggestion->reply_message_id);
        $this->assertTrue((bool) $suggestion->edited);

        $stats = $service->stats(7);
        $this->assertSame(1, $stats['generated']);
        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['needs_human']);
        $this->assertSame('支付退款', $stats['top_categories'][0]['category']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function saveSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate($key, $value);
        }
    }

    private function createSettingsTable(): void
    {
        Schema::create('v2_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
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
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createTicketTablesForAi(): void
    {
        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('subject')->nullable();
            $table->integer('level')->default(0);
            $table->integer('status')->default(0);
            $table->integer('reply_status')->nullable();
            $table->integer('last_reply_user_id')->nullable();
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
    }

    private function createTicketAiSuggestionTable(): void
    {
        Schema::create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->integer('admin_id')->nullable();
            $table->string('model')->nullable();
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
}
