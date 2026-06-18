<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentSiteSettingService;
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

    public function test_create_without_agent_domain_id_stores_default_scope_with_null_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $setting = AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ])->fresh();

        $this->assertNull($setting->agent_domain_id);
        $this->assertSame(AgentSiteSetting::SCOPE_DEFAULT, $setting->setting_scope);
        $this->assertSame(AgentSiteSetting::KEY_DEFAULT, $setting->setting_key);
    }

    public function test_create_with_empty_agent_domain_id_stores_default_scope_with_null_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $setting = AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => '',
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ])->fresh();

        $this->assertNull($setting->agent_domain_id);
        $this->assertSame(AgentSiteSetting::SCOPE_DEFAULT, $setting->setting_scope);
        $this->assertSame(AgentSiteSetting::KEY_DEFAULT, $setting->setting_key);
    }

    public function test_saving_partially_selected_domain_setting_keeps_domain_scope(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $setting = $this->createSiteSetting($agent, $domain);

        $partialSetting = AgentSiteSetting::query()
            ->select(['id', 'site_name'])
            ->findOrFail($setting->id);

        $partialSetting->site_name = 'Updated Agent Site';
        $partialSetting->save();

        $storedSetting = AgentSiteSetting::query()->findOrFail($setting->id);

        $this->assertSame($domain->id, $storedSetting->agent_domain_id);
        $this->assertSame(AgentSiteSetting::SCOPE_DOMAIN, $storedSetting->setting_scope);
        $this->assertSame((string) $domain->id, $storedSetting->setting_key);
        $this->assertSame('Updated Agent Site', $storedSetting->site_name);
    }

    public function test_domain_resolves_its_site_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $setting = $this->createSiteSetting($agent, $domain);

        $this->assertSame($setting->id, $domain->siteSetting->id);
        $this->assertSame('Agent Site', $domain->siteSetting->site_name);
    }

    public function test_save_rejects_unowned_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $domain = $this->createActiveDomain($otherAgent, 'other.example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent domain is not available');

        app(AgentSiteSettingService::class)->save($agent, [
            'agent_domain_id' => $domain->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
        ]);
    }

    public function test_save_rejects_malformed_non_empty_domain_ids(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentSiteSettingService::class);

        foreach (['abc', 'undefined', 0, -1] as $domainId) {
            try {
                $service->save($agent, [
                    'agent_domain_id' => $domainId,
                    'site_name' => 'Agent Site',
                ]);
                $this->fail('Expected unavailable domain exception.');
            } catch (ApiException $exception) {
                $this->assertSame('Agent domain is not available', $exception->getMessage());
            }
        }

        $this->assertSame(0, AgentSiteSetting::query()->count());
    }

    public function test_save_rejects_existing_setting_domain_scope_change(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $setting = $this->createSiteSetting($agent);

        try {
            app(AgentSiteSettingService::class)->save($agent, [
                'id' => $setting->id,
                'agent_domain_id' => $domain->id,
                'site_name' => 'Moved Site',
            ]);
            $this->fail('Expected domain change exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Agent site setting domain cannot be changed', $exception->getMessage());
        }

        $setting->refresh();
        $this->assertNull($setting->agent_domain_id);
        $this->assertSame('Agent Site', $setting->site_name);
        $this->assertSame(0, AgentSiteSetting::query()
            ->where('agent_user_id', $agent->id)
            ->where('agent_domain_id', $domain->id)
            ->count());
    }

    public function test_save_rejects_unknown_setting_id_for_agent(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $setting = $this->createSiteSetting($otherAgent);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent site setting is not available');

        app(AgentSiteSettingService::class)->save($agent, [
            'id' => $setting->id,
            'site_name' => 'Updated Site',
        ]);
    }

    public function test_save_partial_update_preserves_legacy_invalid_omitted_url(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $setting = $this->createSiteSetting($agent, null, [
            'support_url' => 'legacy invalid url',
        ]);

        $payload = app(AgentSiteSettingService::class)->save($agent, [
            'id' => $setting->id,
            'site_name' => 'Updated Site',
        ]);
        $setting->refresh();

        $this->assertSame('Updated Site', $payload['site_name']);
        $this->assertSame('legacy invalid url', $payload['support_url']);
        $this->assertSame('legacy invalid url', $setting->support_url);
    }

    public function test_save_creates_default_setting_when_domain_is_missing(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $payload = app(AgentSiteSettingService::class)->save($agent, [
            'site_name' => 'New Agent Site',
            'logo_url' => 'https://example.test/logo.png',
            'landing_theme' => 'spark',
            'accent_color' => '#AABBCC',
            'enabled' => true,
        ]);

        $this->assertSame('New Agent Site', $payload['site_name']);
        $this->assertSame('https://example.test/logo.png', $payload['logo_url']);
        $this->assertSame('spark', $payload['landing_theme']);
        $this->assertSame('#aabbcc', $payload['accent_color']);
        $this->assertNull($payload['agent_domain_id']);
        $this->assertSame(AgentSiteSetting::SCOPE_DEFAULT, $payload['setting_scope']);
        $this->assertSame(AgentSiteSetting::KEY_DEFAULT, $payload['setting_key']);
    }

    public function test_resolve_prefers_domain_setting_then_default_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Site',
            'logo_url' => 'https://example.test/default-logo.png',
            'announcement' => 'Default announcement',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Domain Site',
            'logo_url' => 'https://example.test/domain-logo.png',
        ]);

        $payload = app(AgentSiteSettingService::class)->resolve([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
        ]);

        $this->assertSame('Domain Site', $payload['site_name']);
        $this->assertSame('https://example.test/domain-logo.png', $payload['logo_url']);
        $this->assertSame('Default announcement', $payload['announcement']);
    }

    public function test_resolve_returns_empty_when_default_setting_is_disabled(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Disabled Site',
            'enabled' => false,
        ]);

        $this->assertSame([], app(AgentSiteSettingService::class)->resolve([
            'agent_user_id' => $agent->id,
        ]));
    }

    public function test_resolve_uses_default_when_domain_setting_is_disabled(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Site',
            'announcement' => 'Default announcement',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Disabled Domain Site',
            'enabled' => false,
        ]);

        $payload = app(AgentSiteSettingService::class)->resolve([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
        ]);

        $this->assertSame('Default Site', $payload['site_name']);
        $this->assertSame('Default announcement', $payload['announcement']);
        $this->assertNull($payload['agent_domain_id']);
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

    public function test_migration_rollback_preserves_pre_existing_ticket_agent_columns(): void
    {
        $this->replaceTicketTableWithLegacyAgentColumns();
        DB::table('v2_ticket')->insert([
            'user_id' => 1,
            'agent_user_id' => 2,
            'agent_domain_id' => 3,
            'legacy_marker' => 'ticket-kept',
        ]);
        $migration = $this->agentSiteSettingMigration();

        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasTable('v2_ticket'));
        $this->assertTrue(Schema::hasColumn('v2_ticket', 'agent_user_id'));
        $this->assertTrue(Schema::hasColumn('v2_ticket', 'agent_domain_id'));
        $this->assertSame(2, DB::table('v2_ticket')->value('agent_user_id'));
        $this->assertSame(3, DB::table('v2_ticket')->value('agent_domain_id'));
        $this->assertSame('ticket-kept', DB::table('v2_ticket')->value('legacy_marker'));
    }

    public function test_migration_rollback_preserves_partial_overlap_site_setting_table(): void
    {
        Schema::drop('v2_agent_site_setting');
        Schema::create('v2_agent_site_setting', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('agent_user_id')->index();
            $table->string('setting_scope', 16)->default('default');
            $table->string('setting_key', 64)->default('default');
            $table->string('site_name', 80)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->string('legacy_marker', 32)->nullable();
            $table->unique(['agent_user_id', 'setting_scope', 'setting_key'], 'uniq_agent_site_setting_scope');
        });
        DB::table('v2_agent_site_setting')->insert([
            'agent_user_id' => 1,
            'setting_scope' => 'default',
            'setting_key' => 'default',
            'legacy_marker' => 'partial-kept',
        ]);
        $migration = $this->agentSiteSettingMigration();

        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasTable('v2_agent_site_setting'));
        $this->assertTrue(Schema::hasColumn('v2_agent_site_setting', 'legacy_marker'));
        $this->assertFalse(Schema::hasColumn('v2_agent_site_setting', 'logo_url'));
        $this->assertSame('partial-kept', DB::table('v2_agent_site_setting')->value('legacy_marker'));
    }

    public function test_migration_rollback_preserves_migration_shaped_site_setting_table(): void
    {
        $this->replaceTicketTableWithLegacyAgentColumns();
        DB::table('v2_agent_site_setting')->insert([
            'agent_user_id' => 1,
            'agent_domain_id' => 2,
            'setting_scope' => 'default',
            'setting_key' => 'default',
            'site_name' => 'Migration Shaped',
        ]);
        $migration = $this->agentSiteSettingMigration();

        $migration->down();

        $this->assertTrue(Schema::hasTable('v2_agent_site_setting'));
        $this->assertSame('Migration Shaped', DB::table('v2_agent_site_setting')->value('site_name'));
    }

    private function agentSiteSettingMigration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_06_18_000003_create_agent_site_setting_table.php';
    }

    private function replaceTicketTableWithLegacyAgentColumns(): void
    {
        Schema::drop('v2_ticket_message_attachment');
        Schema::drop('v2_ticket_message');
        Schema::drop('v2_ticket');
        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->string('legacy_marker', 32)->nullable();
        });
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

    private function createSiteSetting(User $agent, ?AgentDomain $domain = null, array $attributes = []): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create(array_merge([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }
}
