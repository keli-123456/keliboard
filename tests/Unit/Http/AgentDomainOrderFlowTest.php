<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Passport\AuthRegister;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\Auth\RegisterService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentDomainOrderFlowTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->bindTestHasher();
        $this->bindTestSettings([
            'captcha_enable' => 0,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'email_verify' => 0,
            'register_limit_by_ip_enable' => 0,
            'try_out_plan_id' => 0,
            'default_remind_expire' => 1,
            'default_remind_traffic' => 1,
        ]);
    }

    public function test_registration_through_agent_domain_binds_user_to_agent(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = AuthRegister::create('/api/v1/passport/auth/register', 'POST', [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);

        [$success, $result] = app(RegisterService::class)->register($request);

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);
        $registeredUser = $result->fresh();
        $this->assertSame($agent->id, (int) $registeredUser->invite_user_id);
        $this->assertSame(1, DB::table('v2_agent_user')
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $registeredUser->id)
            ->count());
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 10000,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

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
}
