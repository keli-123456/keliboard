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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
            $content = $payload['messages'][1]['content'] ?? '';

            return $request->hasHeader('Authorization', 'Bearer sk-test')
                && $payload['model'] === 'test-model'
                && str_contains($content, 'Windows 客户端无法连接')
                && str_contains($content, 'Windows 客户端连接排查');
        });
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
