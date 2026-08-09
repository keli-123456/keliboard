<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\MarketingController;
use App\Models\AgentDomain;
use App\Models\AgentUser;
use App\Models\MarketingRule;
use App\Models\MarketingTemplate;
use App\Models\MessageDispatchLog;
use App\Models\MessageDispatchTask;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\MarketingAutomationService;
use App\Services\MessageDispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminMarketingSiteScopeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestSettings([
            'app_name' => 'Main Site',
            'app_url' => 'https://main.example.test',
            'message_ops_enable' => true,
        ]);

        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createMarketingTables();
    }

    public function test_templates_scope_includes_global_and_selected_site_templates(): void
    {
        $firstSite = $this->createSite('first', 'First Site', 'first.example.test');
        $secondSite = $this->createSite('second', 'Second Site', 'second.example.test');

        $global = $this->createTemplate('global_custom', 'Global Custom');
        $first = $this->createTemplate('first_custom', 'First Custom', ['scope_type' => 'site', 'site_id' => $firstSite->id]);
        $second = $this->createTemplate('second_custom', 'Second Custom', ['scope_type' => 'site', 'site_id' => $secondSite->id]);

        $payload = $this->responsePayload(app(MarketingController::class)->templates(Request::create('/admin/marketing/templates', 'GET', [
            'scope_type' => 'site',
            'site_id' => $firstSite->id,
        ])));
        $ids = collect($payload['data'])->pluck('id')->all();

        $this->assertContains($global->id, $ids);
        $this->assertContains($first->id, $ids);
        $this->assertNotContains($second->id, $ids);

        $globalPayload = $this->responsePayload(app(MarketingController::class)->templates(Request::create('/admin/marketing/templates', 'GET', [
            'scope_type' => 'global',
        ])));
        $globalIds = collect($globalPayload['data'])->pluck('id')->all();

        $this->assertContains($global->id, $globalIds);
        $this->assertNotContains($first->id, $globalIds);
    }

    public function test_logs_can_be_filtered_by_global_or_site_scope(): void
    {
        $firstSite = $this->createSite('first', 'First Site', 'first.example.test');
        $secondSite = $this->createSite('second', 'Second Site', 'second.example.test');

        $globalLog = $this->createDispatchLog('global@example.test');
        $firstLog = $this->createDispatchLog('first@example.test', ['scope_type' => 'site', 'site_id' => $firstSite->id]);
        $secondLog = $this->createDispatchLog('second@example.test', ['scope_type' => 'site', 'site_id' => $secondSite->id]);

        $sitePayload = $this->responsePayload(app(MarketingController::class)->logs(Request::create('/admin/marketing/logs', 'GET', [
            'scope_type' => 'site',
            'site_id' => $firstSite->id,
        ])));
        $siteIds = collect($sitePayload['data']['items'])->pluck('id')->all();

        $this->assertSame([$firstLog->id], $siteIds);

        $globalPayload = $this->responsePayload(app(MarketingController::class)->logs(Request::create('/admin/marketing/logs', 'GET', [
            'scope_type' => 'global',
        ])));
        $globalIds = collect($globalPayload['data']['items'])->pluck('id')->all();

        $this->assertContains($globalLog->id, $globalIds);
        $this->assertNotContains($firstLog->id, $globalIds);
        $this->assertNotContains($secondLog->id, $globalIds);
    }

    public function test_marketing_tasks_are_tagged_with_user_site_scope(): void
    {
        $site = $this->createSite('first', 'First Site', 'first.example.test');
        $user = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
        $template = $this->createTemplate('welcome_site_scope', 'Welcome');
        $rule = MarketingRule::query()->create([
            'code' => 'welcome_site_scope',
            'scene' => 'registered_no_purchase_1d',
            'name' => 'Welcome',
            'message_type' => MarketingRule::TYPE_MARKETING,
            'description' => 'Welcome',
            'enabled' => true,
            'email_enabled' => true,
            'telegram_enabled' => false,
            'email_template_id' => $template->id,
            'telegram_template_id' => null,
            'priority' => 100,
            'cooldown_hours' => 24,
            'daily_user_limit' => 1,
            'trigger_config' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $rule->load('emailTemplate');

        $method = new ReflectionMethod(MarketingAutomationService::class, 'queueForUserRule');
        $method->setAccessible(true);
        $queued = $method->invoke(app(MarketingAutomationService::class), $rule, $user, 'scope-test:' . $user->id, []);

        $this->assertTrue($queued);
        $task = MessageDispatchTask::query()->first();
        $this->assertSame('site', $task->scope_type);
        $this->assertSame($site->id, (int) $task->site_id);
        $this->assertNull($task->agent_user_id);
        $this->assertSame($site->id, (int) ($task->context['site_id'] ?? 0));
    }

    public function test_marketing_scan_excludes_agent_subordinate_users(): void
    {
        $this->createOrderTable();
        $agent = $this->createUser('agent@example.test');
        $subordinate = $this->createUser('subordinate@example.test', [
            'created_at' => CarbonImmutable::today()->subDay()->timestamp + 60,
        ]);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $template = $this->createTemplate('registered_no_purchase_1d_email', 'Registered No Purchase');
        $rule = MarketingRule::query()->create([
            'code' => 'registered_no_purchase_1d',
            'scene' => MarketingRule::SCENE_REGISTERED_NO_PURCHASE_1D,
            'name' => 'Registered No Purchase',
            'message_type' => MarketingRule::TYPE_MARKETING,
            'description' => 'Registered but not purchased',
            'enabled' => true,
            'email_enabled' => true,
            'telegram_enabled' => false,
            'email_template_id' => $template->id,
            'telegram_template_id' => null,
            'priority' => 100,
            'cooldown_hours' => 24,
            'daily_user_limit' => 1,
            'trigger_config' => ['delay_days' => 1],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $rule->load('emailTemplate');

        $method = new ReflectionMethod(MarketingAutomationService::class, 'scanRegisteredNoPurchaseRule');
        $method->setAccessible(true);
        $result = $method->invoke(app(MarketingAutomationService::class), $rule);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, MessageDispatchTask::query()->count());
    }

    public function test_marketing_task_uses_matching_site_template_over_global_template(): void
    {
        $site = $this->createSite('first', 'First Site', 'first.example.test');
        $user = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
        $globalTemplate = $this->createTemplate('renewal_reminder_email', 'Global Renewal', [
            'subject' => 'Global renewal',
            'content' => 'Global content for {{app_name}}',
        ]);
        $siteTemplate = $this->createTemplate('renewal_reminder_email', 'Site Renewal', [
            'subject' => 'Site renewal',
            'content' => 'Site content for {{app_name}}',
            'scope_type' => 'site',
            'site_id' => $site->id,
        ]);
        $rule = $this->createMarketingRule('renewal_site_override', $globalTemplate);
        $rule->load('emailTemplate');

        $queued = $this->queueRuleForUser($rule, $user, 'tenant-template:site:' . $user->id);

        $this->assertTrue($queued);
        $task = MessageDispatchTask::query()->firstOrFail();
        $this->assertSame($siteTemplate->id, (int) $task->template_id);
        $this->assertSame('Site renewal', $task->subject);
        $this->assertSame('Site content for First Site', $task->payload['template_value']['content'] ?? null);
        $this->assertSame('site', $task->scope_type);
        $this->assertSame($site->id, (int) $task->site_id);
    }

    public function test_marketing_task_uses_agent_template_before_site_template(): void
    {
        $site = $this->createSite('first', 'First Site', 'first.example.test');
        $agent = $this->createUser('agent@example.test', ['site_id' => $site->id]);
        $user = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $globalTemplate = $this->createTemplate('agent_notice_email', 'Global Agent Notice', [
            'subject' => 'Global notice',
        ]);
        $this->createTemplate('agent_notice_email', 'Site Agent Notice', [
            'subject' => 'Site notice',
            'scope_type' => 'site',
            'site_id' => $site->id,
        ]);
        $agentTemplate = $this->createTemplate('agent_notice_email', 'Agent Notice', [
            'subject' => 'Agent notice',
            'content' => 'Agent content for {{app_url}}',
            'scope_type' => 'agent',
            'site_id' => $site->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
        ]);
        $rule = $this->createMarketingRule('agent_template_override', $globalTemplate);
        $rule->load('emailTemplate');

        $queued = $this->queueRuleForUser($rule, $user, 'tenant-template:agent:' . $user->id);

        $this->assertTrue($queued);
        $task = MessageDispatchTask::query()->firstOrFail();
        $this->assertSame($agentTemplate->id, (int) $task->template_id);
        $this->assertSame('Agent notice', $task->subject);
        $this->assertSame('Agent content for https://agent.example.test', $task->payload['template_value']['content'] ?? null);
        $this->assertSame('agent', $task->scope_type);
        $this->assertSame($agent->id, (int) $task->agent_user_id);
        $this->assertSame($domain->id, (int) $task->agent_domain_id);
    }

    public function test_marketing_task_falls_back_to_global_template_without_tenant_override(): void
    {
        $site = $this->createSite('first', 'First Site', 'first.example.test');
        $user = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
        $globalTemplate = $this->createTemplate('global_only_email', 'Global Only', [
            'subject' => 'Global only',
            'content' => 'Global only content for {{app_name}}',
        ]);
        $rule = $this->createMarketingRule('global_only_rule', $globalTemplate);
        $rule->load('emailTemplate');

        $queued = $this->queueRuleForUser($rule, $user, 'tenant-template:global:' . $user->id);

        $this->assertTrue($queued);
        $task = MessageDispatchTask::query()->firstOrFail();
        $this->assertSame($globalTemplate->id, (int) $task->template_id);
        $this->assertSame('Global only', $task->subject);
        $this->assertSame('Global only content for First Site', $task->payload['template_value']['content'] ?? null);
        $this->assertSame('site', $task->scope_type);
        $this->assertSame($site->id, (int) $task->site_id);
    }

    public function test_seed_defaults_preserves_custom_rules_and_scoped_templates(): void
    {
        $site = $this->createSite('first', 'First Site', 'first.example.test');
        $scopedTemplate = $this->createTemplate(
            'registered_no_purchase_1d_email',
            'Site Custom',
            [
                'subject' => 'Site custom subject',
                'content' => 'Site custom content',
                'scope_type' => 'site',
                'site_id' => $site->id,
            ]
        );

        $service = app(MarketingAutomationService::class);
        $service->seedDefaults();

        $globalTemplate = MarketingTemplate::query()
            ->where('code', 'registered_no_purchase_1d_email')
            ->where('scope_type', MarketingTemplate::SCOPE_GLOBAL)
            ->whereNull('site_id')
            ->firstOrFail();
        $rule = MarketingRule::query()
            ->where('code', 'registered_no_purchase_1d')
            ->firstOrFail();

        $globalTemplate->update([
            'subject' => 'Customized global subject',
            'content' => 'Customized global content',
            'enabled' => false,
        ]);
        $rule->update([
            'enabled' => false,
            'email_enabled' => false,
            'cooldown_hours' => 240,
            'daily_user_limit' => 3,
            'priority' => 333,
        ]);

        $service->seedDefaults();

        $this->assertSame('Site custom subject', $scopedTemplate->fresh()->subject);
        $this->assertSame('Site custom content', $scopedTemplate->fresh()->content);
        $this->assertSame('Customized global subject', $globalTemplate->fresh()->subject);
        $this->assertFalse((bool) $globalTemplate->fresh()->enabled);

        $rule->refresh();
        $this->assertFalse((bool) $rule->enabled);
        $this->assertFalse((bool) $rule->email_enabled);
        $this->assertSame(240, (int) $rule->cooldown_hours);
        $this->assertSame(3, (int) $rule->daily_user_limit);
        $this->assertSame(333, (int) $rule->priority);
        $this->assertSame(
            2,
            MarketingTemplate::query()
                ->where('code', 'registered_no_purchase_1d_email')
                ->count()
        );
    }

    public function test_disabling_rule_or_channel_cancels_queued_and_claimed_tasks(): void
    {
        $emailTemplate = $this->createTemplate('disable_email', 'Disable Email');
        $telegramTemplate = $this->createTemplate('disable_telegram', 'Disable Telegram', [
            'channel' => MarketingTemplate::CHANNEL_TELEGRAM,
            'subject' => null,
        ]);
        $rule = $this->createMarketingRule('disable_rule', $emailTemplate);
        $rule->update([
            'telegram_enabled' => true,
            'telegram_template_id' => $telegramTemplate->id,
        ]);

        $taskDefaults = [
            'rule_id' => $rule->id,
            'message_type' => MarketingRule::TYPE_MARKETING,
            'priority' => 100,
            'state' => MessageDispatchTask::STATE_PENDING,
            'scope_type' => 'global',
            'scheduled_at' => time(),
            'available_at' => time(),
            'attempt_count' => 0,
            'max_attempts' => 3,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        $emailTask = MessageDispatchTask::query()->create(array_merge($taskDefaults, [
            'template_id' => $emailTemplate->id,
            'channel' => MarketingTemplate::CHANNEL_EMAIL,
            'dedupe_key' => 'disable-rule-email',
        ]));
        $telegramTask = MessageDispatchTask::query()->create(array_merge($taskDefaults, [
            'template_id' => $telegramTemplate->id,
            'channel' => MarketingTemplate::CHANNEL_TELEGRAM,
            'dedupe_key' => 'disable-rule-telegram',
        ]));

        $this->responsePayload(app(MarketingController::class)->updateRule(Request::create(
            '/admin/marketing/rule/update',
            'POST',
            [
                'id' => $rule->id,
                'enabled' => true,
                'email_enabled' => false,
                'telegram_enabled' => true,
                'email_template_id' => $emailTemplate->id,
                'telegram_template_id' => $telegramTemplate->id,
                'cooldown_hours' => 24,
                'daily_user_limit' => 1,
                'priority' => 100,
            ]
        )));

        $this->assertSame(MessageDispatchTask::STATE_CANCELLED, $emailTask->fresh()->state);
        $this->assertSame(MessageDispatchTask::STATE_PENDING, $telegramTask->fresh()->state);

        $this->responsePayload(app(MarketingController::class)->updateRule(Request::create(
            '/admin/marketing/rule/update',
            'POST',
            [
                'id' => $rule->id,
                'enabled' => false,
                'email_enabled' => true,
                'telegram_enabled' => true,
                'email_template_id' => $emailTemplate->id,
                'telegram_template_id' => $telegramTemplate->id,
                'cooldown_hours' => 24,
                'daily_user_limit' => 1,
                'priority' => 100,
            ]
        )));

        $this->assertSame(MessageDispatchTask::STATE_CANCELLED, $telegramTask->fresh()->state);

        $claimedTask = MessageDispatchTask::query()->create(array_merge($taskDefaults, [
            'template_id' => $emailTemplate->id,
            'channel' => MarketingTemplate::CHANNEL_EMAIL,
            'state' => MessageDispatchTask::STATE_SENDING,
            'reserved_at' => time(),
            'dedupe_key' => 'disable-rule-claimed',
        ]));
        app(MessageDispatchService::class)->processTask($claimedTask->id);

        $claimedTask->refresh();
        $this->assertSame(MessageDispatchTask::STATE_CANCELLED, $claimedTask->state);
        $this->assertNull($claimedTask->reserved_at);
        $this->assertSame('marketing rule or channel disabled', $claimedTask->last_error);
    }

    private function createMarketingTables(): void
    {
        $this->database->schema()->create('v2_marketing_template', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 64)->index();
            $table->string('name', 128);
            $table->string('channel', 32);
            $table->string('message_type', 32)->default(MarketingRule::TYPE_MARKETING);
            $table->string('subject')->nullable();
            $table->text('content');
            $table->boolean('enabled')->default(true);
            $table->boolean('is_system')->default(false);
            $table->json('variables')->nullable();
            $this->addScopeColumns($table);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_marketing_rule', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 64)->unique();
            $table->string('scene', 64)->unique();
            $table->string('name', 128);
            $table->string('message_type', 32)->default(MarketingRule::TYPE_MARKETING);
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('telegram_enabled')->default(false);
            $table->unsignedInteger('email_template_id')->nullable();
            $table->unsignedInteger('telegram_template_id')->nullable();
            $table->integer('priority')->default(100);
            $table->integer('cooldown_hours')->default(24);
            $table->integer('daily_user_limit')->default(1);
            $table->json('trigger_config')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_message_dispatch_task', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('rule_id')->nullable();
            $table->unsignedInteger('template_id')->nullable();
            $table->string('channel', 32);
            $table->string('message_type', 32);
            $table->integer('priority')->default(100);
            $table->string('state', 32);
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('to_address')->nullable();
            $table->string('subject')->nullable();
            $table->json('payload')->nullable();
            $table->json('context')->nullable();
            $this->addScopeColumns($table);
            $table->integer('scheduled_at')->nullable();
            $table->integer('available_at')->nullable();
            $table->integer('reserved_at')->nullable();
            $table->integer('sent_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->string('failure_classification')->nullable();
            $table->text('last_error')->nullable();
            $table->text('provider_response')->nullable();
            $table->integer('last_recovered_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_message_dispatch_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('task_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('rule_id')->nullable();
            $table->unsignedInteger('template_id')->nullable();
            $table->unsignedInteger('mail_log_id')->nullable();
            $table->string('channel', 32);
            $table->string('message_type', 32);
            $table->string('status', 32);
            $table->integer('attempt')->default(1);
            $table->string('to_address')->nullable();
            $table->string('subject')->nullable();
            $table->string('failure_classification')->nullable();
            $table->string('provider_health_status')->nullable();
            $table->text('error_message')->nullable();
            $table->text('provider_response')->nullable();
            $table->json('context')->nullable();
            $this->addScopeColumns($table);
            $table->text('manual_note')->nullable();
            $table->integer('noted_by_admin_id')->nullable();
            $table->integer('noted_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function addScopeColumns(Blueprint $table): void
    {
        $table->string('scope_type', 32)->default('global')->index();
        $table->unsignedInteger('site_id')->nullable()->index();
        $table->unsignedInteger('agent_user_id')->nullable()->index();
        $table->unsignedInteger('agent_domain_id')->nullable()->index();
    }

    private function createSite(string $code, string $name, string $domain): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createTemplate(string $code, string $name, array $attributes = []): MarketingTemplate
    {
        return MarketingTemplate::query()->create(array_merge([
            'code' => $code,
            'name' => $name,
            'channel' => MarketingTemplate::CHANNEL_EMAIL,
            'message_type' => MarketingRule::TYPE_MARKETING,
            'subject' => 'Hello {{app_name}}',
            'content' => 'Hello {{user_email}}',
            'enabled' => true,
            'is_system' => false,
            'variables' => ['app_name', 'user_email'],
            'scope_type' => 'global',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function createMarketingRule(string $code, MarketingTemplate $emailTemplate): MarketingRule
    {
        return MarketingRule::query()->create([
            'code' => $code,
            'scene' => $code,
            'name' => $code,
            'message_type' => MarketingRule::TYPE_MARKETING,
            'description' => $code,
            'enabled' => true,
            'email_enabled' => true,
            'telegram_enabled' => false,
            'email_template_id' => $emailTemplate->id,
            'telegram_template_id' => null,
            'priority' => 100,
            'cooldown_hours' => 24,
            'daily_user_limit' => 1,
            'trigger_config' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function queueRuleForUser(MarketingRule $rule, User $user, string $dedupeKey): bool
    {
        $method = new ReflectionMethod(MarketingAutomationService::class, 'queueForUserRule');
        $method->setAccessible(true);

        return (bool) $method->invoke(app(MarketingAutomationService::class), $rule, $user, $dedupeKey, []);
    }

    private function createDispatchLog(string $target, array $attributes = []): MessageDispatchLog
    {
        return MessageDispatchLog::query()->create(array_merge([
            'channel' => MarketingTemplate::CHANNEL_EMAIL,
            'message_type' => MarketingRule::TYPE_MARKETING,
            'status' => MessageDispatchLog::STATUS_SUCCESS,
            'attempt' => 1,
            'to_address' => $target,
            'scope_type' => 'global',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function createUser(string $email, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'secret',
            'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'banned' => false,
            'is_admin' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function bindRequestValidateMacro(): void
    {
        if (Request::hasMacro('validate')) {
            return;
        }

        Request::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
