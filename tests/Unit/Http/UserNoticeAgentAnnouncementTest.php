<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\NoticeController;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\Notice;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserNoticeAgentAnnouncementTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createNoticeTable();
    }

    public function test_bound_subordinate_with_agent_site_announcement_gets_synthetic_notice_first(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'site_name' => 'Agent Storefront',
            'announcement' => 'Welcome buyers',
            'enabled' => true,
            'created_at' => 1710000000,
            'updated_at' => 1710000100,
        ]);
        $global = $this->createNotice('Global Notice', 'Global content', [
            'sort' => 1,
            'show' => true,
        ]);

        $payload = $this->responsePayload(app(NoticeController::class)->fetch($this->noticeRequest($buyer)));

        $this->assertSame(2, $payload['total']);
        $this->assertCount(2, $payload['data']);
        $this->assertSame('agent-announcement', $payload['data'][0]['id']);
        $this->assertSame('Agent Storefront', $payload['data'][0]['title']);
        $this->assertSame('Welcome buyers', $payload['data'][0]['content']);
        $this->assertTrue($payload['data'][0]['show']);
        $this->assertTrue($payload['data'][0]['agent_context']);
        $this->assertSame($global->id, $payload['data'][1]['id']);
        $this->assertSame('Global Notice', $payload['data'][1]['title']);
        $this->assertSame(1, Notice::query()->count());
    }

    public function test_normal_user_notice_response_remains_unchanged_without_agent_context_on_global_notice(): void
    {
        $user = $this->createUser('user@example.test');
        $global = $this->createNotice('Global Notice', 'Global content', [
            'sort' => 1,
            'show' => true,
        ]);

        $payload = $this->responsePayload(app(NoticeController::class)->fetch($this->noticeRequest($user)));

        $this->assertSame(1, $payload['total']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame($global->id, $payload['data'][0]['id']);
        $this->assertSame('Global Notice', $payload['data'][0]['title']);
        $this->assertSame('Global content', $payload['data'][0]['content']);
        $this->assertTrue($payload['data'][0]['show']);
        $this->assertArrayNotHasKey('agent_context', $payload['data'][0]);
    }

    private function createNoticeTable(): void
    {
        $this->database->schema()->create('v2_notice', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('sort')->nullable()->index();
            $table->string('title');
            $table->text('content');
            $table->boolean('show')->default(false);
            $table->string('img_url')->nullable();
            $table->string('tags')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function createNotice(string $title, string $content, array $attributes = []): Notice
    {
        return Notice::query()->create(array_merge([
            'title' => $title,
            'content' => $content,
            'show' => true,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
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

    private function noticeRequest(User $user, int $current = 1): Request
    {
        $request = Request::create('/api/v1/user/notice/fetch', 'GET', [
            'current' => $current,
        ]);
        $request->headers->set('host', 'panel.example.test');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
