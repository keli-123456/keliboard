<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Knowledge;
use App\Models\Setting as SettingModel;
use App\Models\Ticket;
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

        $result = (new TicketAiAssistantService())->suggest($ticket);

        $this->assertSame('客户端连接', $result['category']);
        $this->assertSame('您好，请先确认客户端核心已启动，再刷新订阅并重新测试节点。', $result['draft']);
        $this->assertFalse($result['needs_human']);
        $this->assertSame('Windows 客户端连接排查', $result['matched_knowledge'][0]['title']);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $content = $payload['messages'][1]['content'] ?? '';

            return $request->hasHeader('Authorization', 'Bearer sk-test')
                && $payload['model'] === 'test-model'
                && str_contains($content, 'Windows 客户端无法连接')
                && str_contains($content, 'Windows 客户端连接排查');
        });
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
}
