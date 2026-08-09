<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentUser;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MailServiceAgentExclusionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
    }

    public function test_reminder_mail_queries_exclude_agent_subordinate_users(): void
    {
        $normalUser = $this->createUser('normal@example.test');
        $agent = $this->createUser('agent@example.test', [
            'remind_expire' => false,
            'remind_traffic' => false,
        ]);
        $subordinate = $this->createUser('subordinate@example.test');

        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = app(MailService::class);

        $this->assertSame(1, $service->getTotalUsersNeedRemind());

        $statistics = $service->processUsersInChunks(10);
        $this->assertSame(1, $statistics['processed_users']);
        $this->assertSame(1, $statistics['skipped']);
        $this->assertSame($normalUser->id, User::query()->where('email', 'normal@example.test')->value('id'));
    }

    private function createUser(string $email, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'secret',
            'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) Str::uuid(),
            'banned' => false,
            'is_admin' => false,
            'remind_expire' => true,
            'remind_traffic' => true,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }
}

