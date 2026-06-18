<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentSiteSettingServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createTicketTables();
    }

    public function test_agent_site_setting_casts_enabled_and_resolves_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $setting = AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'site_name' => 'Agent Site',
            'enabled' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue($setting->enabled);
        $this->assertSame('agent.example.test', $setting->domain->domain);
    }

    public function test_query_create_persists_one_default_setting_and_one_domain_setting_with_events_disabled(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $defaultSetting = $this->createSiteSetting($agent);
        $domainSetting = $this->createSiteSetting($agent, $domain);

        $this->assertSame('default', $defaultSetting->setting_scope);
        $this->assertSame('default', $defaultSetting->setting_key);
        $this->assertSame('domain', $domainSetting->setting_scope);
        $this->assertSame((string) $domain->id, $domainSetting->setting_key);
        $this->assertSame(1, AgentSiteSetting::query()
            ->where('agent_user_id', $agent->id)
            ->where('setting_scope', AgentSiteSetting::SCOPE_DEFAULT)
            ->where('setting_key', AgentSiteSetting::KEY_DEFAULT)
            ->count());
        $this->assertSame(1, AgentSiteSetting::query()
            ->where('agent_user_id', $agent->id)
            ->where('setting_scope', AgentSiteSetting::SCOPE_DOMAIN)
            ->where('setting_key', (string) $domain->id)
            ->count());

        $this->expectException(QueryException::class);

        $this->createSiteSetting($agent);
    }

    public function test_domain_resolves_its_site_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $setting = $this->createSiteSetting($agent, $domain);

        $this->assertSame($setting->id, $domain->siteSetting->id);
        $this->assertSame('Agent Site', $domain->siteSetting->site_name);
    }

    public function test_ticket_resolves_agent_and_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $user = $this->createUser('customer@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $ticket = Ticket::query()->create([
            'user_id' => $user->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'subject' => 'Help',
            'level' => 0,
            'status' => Ticket::STATUS_OPENING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame($agent->id, $ticket->agent->id);
        $this->assertSame('agent.example.test', $ticket->agentDomain->domain);
    }

    public function test_migration_rollback_preserves_pre_existing_site_setting_table(): void
    {
        Schema::drop('v2_agent_site_setting');
        Schema::drop('v2_ticket_message_attachment');
        Schema::drop('v2_ticket_message');
        Schema::drop('v2_ticket');
        Schema::create('v2_agent_site_setting', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('agent_user_id')->index();
            $table->unsignedInteger('agent_domain_id')->nullable()->index();
            $table->string('legacy_marker', 32)->nullable();
        });
        DB::table('v2_agent_site_setting')->insert([
            'agent_user_id' => 1,
            'agent_domain_id' => null,
            'legacy_marker' => 'kept',
        ]);
        $migration = $this->agentSiteSettingMigration();

        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasTable('v2_agent_site_setting'));
        $this->assertTrue(Schema::hasColumn('v2_agent_site_setting', 'legacy_marker'));
        $this->assertFalse(Schema::hasColumn('v2_agent_site_setting', 'setting_scope'));
        $this->assertFalse(Schema::hasColumn('v2_agent_site_setting', 'setting_key'));
        $this->assertSame('kept', DB::table('v2_agent_site_setting')->value('legacy_marker'));
    }

    private function agentSiteSettingMigration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_06_18_000003_create_agent_site_setting_table.php';
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

    private function createActiveDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createSiteSetting(User $agent, ?AgentDomain $domain = null): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
