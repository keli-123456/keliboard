<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\ProcessSubscriptionAiActionJob;
use App\Jobs\SendEmailJob;
use App\Models\Plugin;
use App\Models\SubscriptionAiAction;
use App\Models\User;
use App\Services\NotificationSiteContextService;
use App\Services\SubscriptionAiAutomationService;
use App\Services\SubscriptionAiBenchmarkService;
use App\Services\TicketAiAssistantService;
use App\Services\TicketAiProviderClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\SubscriptionControl\Plugin as ControlPlugin;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionAiAutomationServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private SubscriptionAiAutomationService $service;
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        config(['app.key' => 'synthetic-test-key']);
        Schema::create('v2_plugins', function (Blueprint $table): void {
            $table->increments('id'); $table->string('code'); $table->boolean('is_enabled'); $table->text('config')->nullable(); $table->timestamps();
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id'); $table->string('email'); $table->string('token'); $table->string('uuid');
            $table->integer('site_id')->nullable(); $table->integer('parent_id')->nullable();
            $table->boolean('is_admin')->default(false); $table->boolean('is_staff')->default(false); $table->boolean('banned')->default(false);
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('user_id'); $table->string('code'); $table->integer('created_at');
            $table->string('client_ip')->nullable(); $table->string('ua_category')->nullable(); $table->string('region')->nullable();
            $table->integer('online_ip_count')->nullable(); $table->integer('risk_score')->nullable();
        });
        Schema::create('v2_subscription_control_case_review', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('user_id'); $table->string('status'); $table->integer('reviewed_at');
        });
        (require base_path('database/migrations/2026_11_02_000001_create_subscription_ai_actions.php'))->up();
        Plugin::query()->create(['code' => 'subscription_control', 'is_enabled' => true, 'config' => '{}']);
        app()->instance(Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $ai = new class extends TicketAiAssistantService {
            public function __construct() {}
            public function publicSettings(): array { return ['ticket_ai_enable' => true, 'ticket_ai_api_key_set' => true, 'ticket_ai_base_url' => 'https://ai.example.test/v1', 'ticket_ai_model' => 'synthetic-model']; }
            public function providerSettingsForInternalUse(): array { return ['enabled' => true]; }
        };
        app()->instance(TicketAiAssistantService::class, $ai);
        // Exercise legacy execution mechanics independently of the production admission gate.
        $gate = $this->getMockBuilder(SubscriptionAiBenchmarkService::class)->onlyMethods(['permitsMode', 'permits'])->getMock();
        $gate->method('permitsMode')->willReturn(true); $gate->method('permits')->willReturn(true);
        app()->instance(SubscriptionAiBenchmarkService::class, $gate);
        $this->provider = new class extends TicketAiProviderClient {
            public array $calls = [];
            public array $providerSettings = [];
            public string $action = 'throttle';
            public $during = null;
            public mixed $override = null;
            public function complete(array $settings, array $messages, bool $validateStructuredContent = true): array {
                $this->providerSettings[] = $settings;
                $this->calls[] = $messages;
                if ($this->during) ($this->during)();
                if ($this->override instanceof \Throwable) throw $this->override;
                $payload = json_decode($messages[1]['content'], true);
                return ['content' => json_encode($this->override ?? [
                    'action' => $this->action, 'duration_seconds' => in_array($this->action, ['observe', 'notify', 'reset_token'], true) ? 0 : ($this->action === 'temporary_block' ? 120 : 300),
                    'confidence' => 0.99, 'evidence_ids' => $payload['evidence']['event_ids'], 'reason' => '多类触发证据需要短时保护',
                ], JSON_THROW_ON_ERROR)];
            }
        };
        $this->service = new SubscriptionAiAutomationService($this->provider, $ai);
        app()->instance(SubscriptionAiAutomationService::class, $this->service);
    }

    private function user(int $id = 10, array $changes = []): User
    {
        DB::table('v2_user')->insert($changes + ['id' => $id, 'email' => "synthetic{$id}@example.test", 'token' => "private-token-{$id}", 'uuid' => "private-uuid-{$id}", 'site_id' => 2]);
        return User::query()->findOrFail($id);
    }

    private function enable(array $changes = []): void
    {
        $this->service->saveSettings($changes + ['ai_auto_enabled' => true, 'ai_auto_notify' => false, 'ai_auto_mode' => 'legacy'], true, 99);
    }

    private function evidence(int $id = 10, array $codes = ['multi_ua_pull', 'source_batch_pull', 'multi_region_pull']): void
    {
        foreach ($codes as $code) DB::table('v2_subscription_control_event')->insert([
            'user_id' => $id, 'code' => $code, 'created_at' => time() - 1,
            'client_ip' => '192.0.2.23', 'ua_category' => 'SyntheticClient', 'region' => 'US', 'risk_score' => 80,
        ]);
    }

    private function active(int $id = 10, string $action = 'throttle'): SubscriptionAiAction
    {
        $this->provider->action = $action;
        $record = $this->service->reserve($id);
        $this->assertNotNull($record); $this->service->process((int) $record->id);
        return $record->fresh();
    }

    public function test_disabled_default_does_not_analyze_or_restrict(): void
    {
        $user = $this->user(); $this->evidence();
        $this->assertFalse($this->service->settings()['ai_auto_enabled']);
        $this->assertNull($this->service->reserve(10)); $this->assertNull($this->service->restriction($user));
        $this->assertSame([], $this->provider->calls); $this->assertSame(['queued' => 0], $this->service->tick());
    }

    public function test_shadow_analysis_uses_the_risk_profile_and_keeps_credentials_and_permissions_unchanged(): void
    {
        $this->bindTestSettings(['risk_ai_model' => 'gpt-6-luna', 'risk_ai_max_tokens' => 4096, 'risk_ai_reasoning_effort' => 'low', 'risk_ai_timeout' => 30]);
        $behavior = $this->independent(); $user = $this->user();
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed'); $record = $this->active();
        $this->assertSame('shadow_proposal_not_executed', $record->outcome);
        $this->assertSame('gpt-6-luna', $record->decision['model']);
        $this->assertCount(1, $this->provider->providerSettings);
        $provider = $this->provider->providerSettings[0];
        $this->assertSame(4096, $provider['max_tokens']); $this->assertSame(30, $provider['timeout']);
        $this->assertSame('low', $provider['reasoning_effort']); $this->assertTrue($provider['json_mode']);
        $this->assertNull($this->service->restriction($user));
        $this->assertSame('private-token-10', $user->fresh()->token); $this->assertSame('private-uuid-10', $user->fresh()->uuid);
    }

    public function test_authorization_is_required_and_generic_plugin_config_cannot_enable_it(): void
    {
        Plugin::query()->first()->update(['config' => '{"ai_auto_enabled":true,"ai_auto_allow_reset_token":true}']);
        $this->assertFalse($this->service->settings()['ai_auto_enabled']);
        $this->expectExceptionMessage('authorization_required');
        $this->service->saveSettings(['ai_auto_enabled' => true], false, 99);
    }

    public function test_validated_model_decision_enforces_only_two_pulls_then_expires_without_changing_credentials(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence(); $record = $this->active();
        $this->assertSame('active', $record->status);
        $this->assertNull($this->service->restriction($user)); $this->assertNull($this->service->restriction($user));
        $this->assertSame(429, $this->service->restriction($user)['status']);
        $record->update(['expires_at' => time() - 1]);
        $this->assertNull($this->service->restriction($user)); $this->service->followUp();
        $this->assertSame('expired', $record->fresh()->status);
        $this->assertSame('expired_not_proof_of_recovery', $record->fresh()->outcome);
        $this->assertSame('private-token-10', $user->fresh()->token); $this->assertSame('private-uuid-10', $user->fresh()->uuid);
    }

    public function test_plugin_returns_429_with_retry_after_without_entering_the_credential_reset_path(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence(); $this->active();
        $this->service->restriction($user); $this->service->restriction($user);
        $plugin = new ControlPlugin('subscription_control'); $plugin->setConfig([]);
        try { $plugin->checkSubscribeAccess([], $user, \Illuminate\Http\Request::create('/subscribe')); $this->fail('Expected interception'); }
        catch (\App\Services\Plugin\InterceptResponseException $error) {
            $this->assertSame(429, $error->getResponse()->getStatusCode());
            $this->assertNotNull($error->getResponse()->headers->get('Retry-After'));
        }
        $this->assertSame('private-token-10', $user->fresh()->token);
    }

    public function test_prompt_contains_no_identity_addresses_credentials_or_raw_user_agent(): void
    {
        $this->user(); $this->enable(); $this->evidence(); $record = $this->active();
        $prompt = json_encode($this->provider->calls);
        foreach (['private-token', 'private-uuid', '@example.test', '192.0.2.23', 'SyntheticClient'] as $private) $this->assertStringNotContainsString($private, $prompt);
        foreach (['credential_hash', 'policy_hash', 'intent_key'] as $secret) $this->assertArrayNotHasKey($secret, $record->toArray());
    }

    public function test_observational_baseline_or_single_signal_never_becomes_an_action(): void
    {
        $this->user(); $this->enable(); $this->evidence(10, array_fill(0, 8, 'behavior_baseline_observation'));
        $this->assertNull($this->service->reserve(10));
        $this->evidence(10, array_fill(0, 5, 'multi_region_pull'));
        $this->assertNull($this->service->reserve(10));
    }

    public function test_duplicate_jobs_cooldown_and_global_hourly_budget_are_bounded(): void
    {
        $this->user(); $this->enable(['ai_auto_hourly_budget' => 1]); $this->evidence(); $record = $this->active();
        $this->service->process($record->id); $this->assertCount(1, $this->provider->calls);
        $this->assertNull($this->service->reserve(10));
        $this->user(11); $this->evidence(11); $this->assertNull($this->service->reserve(11));
    }

    public function test_revocation_and_kill_switch_remove_only_ai_protections(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence(); $record = $this->active(10, 'temporary_block');
        $this->assertSame(403, $this->service->restriction($user)['status']);
        $this->service->revoke($record->id, 99); $this->assertNull($this->service->restriction($user));
        $this->assertSame('revoked', $record->fresh()->status);
        $this->assertFalse($user->fresh()->banned);
        $this->service->saveSettings(['ai_auto_enabled' => false], false, 99);
        $this->assertFalse($this->service->settings()['ai_auto_enabled']);
    }

    public function test_expiry_does_not_unban_an_account_or_touch_other_accounts(): void
    {
        $user = $this->user(); $other = $this->user(11, ['parent_id' => 10]);
        $this->enable(); $this->evidence(); $record = $this->active(10, 'temporary_block');
        $user->update(['banned' => true]); $record->update(['expires_at' => time() - 1]); $this->service->followUp();
        $this->assertTrue($user->fresh()->banned); $this->assertFalse($other->fresh()->banned);
        $this->assertSame('private-token-11', $other->fresh()->token);
    }

    public function test_token_or_tenant_changes_during_analysis_prevent_execution(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence();
        $this->provider->during = fn() => $user->update(['site_id' => 3, 'token' => 'changed']);
        $record = $this->active(); $this->assertSame('failed', $record->status);
        $this->assertSame('context_changed', $record->outcome); $this->assertNull($this->service->restriction($user->fresh()));
    }

    public function test_policy_revoked_during_analysis_cannot_be_overwritten(): void
    {
        $this->user(); $this->enable(); $this->evidence();
        $this->provider->during = fn() => $this->service->saveSettings(['ai_auto_enabled' => false], false, 99);
        $record = $this->active(); $this->assertSame('revoked', $record->status);
        $this->assertSame('automation_disabled', $record->outcome);
    }

    public function test_reset_requires_explicit_authorization_and_only_rotates_subscription_token(): void
    {
        $user = $this->user(); $this->enable(['ai_auto_allow_reset_token' => true]);
        $this->evidence(10, ['multi_ua_pull', 'multi_region_pull', 'source_batch_pull', 'rate_limit', 'multi_ua_pull']);
        $record = $this->active(10, 'reset_token'); $this->assertSame('completed', $record->status);
        $this->assertNotSame('private-token-10', $user->fresh()->token); $this->assertSame('private-uuid-10', $user->fresh()->uuid);
        $this->assertFalse($user->fresh()->banned);
    }

    public function test_unauthorized_or_malformed_model_output_never_executes(): void
    {
        $this->user(); $this->enable(); $this->evidence();
        $this->provider->action = 'reset_token'; $record = $this->active(10, 'reset_token');
        $this->assertSame('failed', $record->status); $this->assertSame('unsafe_decision', $record->outcome);
        $this->assertSame('private-token-10', User::query()->find(10)->token);
    }

    public function test_provider_failure_is_recorded_without_retries_or_blanket_denial(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence(); $this->provider->override = new \RuntimeException('Synthetic provider failure');
        $record = $this->active(); $this->assertSame('failed', $record->status);
        $this->assertNull($this->service->restriction($user)); $this->assertTrue(Cache::has('subscription_ai:provider_cooldown'));
        $this->assertSame('private-token-10', $user->fresh()->token);
    }

    public function test_manual_false_positive_review_cancels_an_active_protection(): void
    {
        $user = $this->user(); $this->enable(); $this->evidence(); $record = $this->active(10, 'temporary_block');
        DB::table('v2_subscription_control_case_review')->insert(['user_id' => 10, 'status' => 'false_positive', 'reviewed_at' => time()]);
        $this->assertNull($this->service->restriction($user)); $this->service->followUp();
        $this->assertSame('revoked', $record->fresh()->status);
    }

    public function test_scheduler_queues_analysis_without_waiting_for_the_model(): void
    {
        $this->user(); $this->enable(); $this->evidence(); $this->assertSame(['queued' => 1], $this->service->tick());
        Bus::assertDispatched(ProcessSubscriptionAiActionJob::class, 1); $this->assertCount(0, $this->provider->calls);
    }

    public function test_candidates_on_cooldown_cannot_starve_other_users(): void
    {
        $this->enable(['ai_auto_hourly_budget' => 30, 'ai_auto_active_limit' => 20]);
        for ($id = 10; $id <= 40; $id++) {
            $this->user($id, ['is_admin' => $id === 10]); $this->evidence($id);
            if ($id > 10 && $id < 40) DB::table('v2_subscription_ai_action')->insert([
                'user_id' => $id, 'site_id' => 2, 'credential_hash' => str_repeat('a', 64), 'policy_hash' => str_repeat('b', 64),
                'intent_key' => hash('sha256', 'cooldown-' . $id), 'status' => 'completed', 'action' => 'observe',
                'evidence' => '{}', 'authorization' => '{}', 'created_at' => time() - 1790, 'updated_at' => time() - 1790,
            ]);
        }
        $this->assertSame(['queued' => 1], $this->service->tick());
        $this->assertSame(40, SubscriptionAiAction::query()->latest('id')->first()->user_id);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_readiness_requires_a_provider_address_and_model_not_just_a_saved_key(): void
    {
        $ai = new class extends TicketAiAssistantService {
            public function __construct() {}
            public function publicSettings(): array { return ['ticket_ai_enable' => true, 'ticket_ai_api_key_set' => true, 'ticket_ai_base_url' => '', 'ticket_ai_model' => '']; }
        };
        $this->assertFalse((new SubscriptionAiAutomationService($this->provider, $ai))->overview()['ai_ready']);
        $this->assertTrue($this->service->overview()['ai_ready']);
    }

    public function test_only_recent_cases_and_non_staff_accounts_are_eligible(): void
    {
        $this->user(10, ['is_admin' => true]); $this->enable(); $this->evidence();
        $this->assertNull($this->service->reserve(10));
        $this->user(11); $this->evidence(11); DB::table('v2_subscription_control_event')->where('user_id', 11)->update(['created_at' => time() - 1000]);
        $this->assertNull($this->service->reserve(11));
    }

    public function test_stale_policy_save_cannot_reenable_a_stopped_automation(): void
    {
        $this->enable(); $revision = $this->service->overview()['policy_revision'];
        $this->service->saveSettings(['ai_auto_enabled' => false], false, 99);
        try { $this->service->saveSettings(['ai_auto_enabled' => true], true, 98, $revision); $this->fail('Expected version conflict'); }
        catch (\RuntimeException $error) { $this->assertSame('policy_changed', $error->getMessage()); }
        $this->assertFalse($this->service->settings()['ai_auto_enabled']);
        $this->assertSame('{}', Plugin::query()->first()->config);
    }

    public function test_model_cannot_choose_another_account_invent_evidence_or_exceed_the_time_limit(): void
    {
        $this->user(); $this->enable(); $this->evidence(); $record = $this->service->reserve(10);
        $valid = ['action' => 'throttle', 'duration_seconds' => 300, 'confidence' => 0.99, 'evidence_ids' => $record->evidence['event_ids'], 'reason' => 'Synthetic evidence'];
        foreach ([['user_id' => 11], ['command' => 'unexpected'], ['duration_seconds' => 901], ['duration_seconds' => -1], ['duration_seconds' => '300'], ['confidence' => 1.1], ['confidence' => 0.2], ['evidence_ids' => [999, 998, 997]], ['evidence_ids' => []], ['reason' => ''], ['action' => 'ban_account']] as $bad) {
            try { $this->service->validateDecision(array_merge($valid, $bad), $record->evidence, $this->service->settings()); $this->fail('Unsafe decision accepted'); }
            catch (\RuntimeException $error) { $this->assertSame('unsafe_decision', $error->getMessage()); }
        }
        $this->assertSame('pending', $record->fresh()->status); $this->assertSame('private-token-10', User::query()->find(10)->token);
    }

    public function test_the_cited_evidence_itself_must_cover_multiple_independent_families(): void
    {
        $this->user(); $this->enable(); $this->evidence(10, ['multi_region_pull', 'multi_region_pull', 'multi_region_pull', 'multi_ua_pull', 'source_batch_pull']);
        $record = $this->service->reserve(10);
        $this->expectExceptionMessage('unsafe_decision');
        $this->service->validateDecision(['action' => 'throttle', 'duration_seconds' => 300, 'confidence' => 0.99, 'evidence_ids' => [1, 2, 3], 'reason' => 'One family only'], $record->evidence, $this->service->settings());
    }

    public function test_a_longer_suspension_requires_the_separate_grant_and_still_expires(): void
    {
        $user = $this->user(); $this->enable(['ai_auto_allow_suspend' => true]);
        $this->evidence(10, ['multi_ua_pull', 'source_batch_pull', 'multi_region_pull', 'rate_limit', 'multi_ua_pull']);
        $record = $this->active(10, 'suspend_subscription');
        $this->assertSame(403, $this->service->restriction($user)['status']);
        $this->assertSame(300, $record->decision['duration_seconds']);
        $this->assertLessThanOrEqual(time() + 300, $record->expires_at);
        $this->assertGreaterThan(time(), $record->expires_at);
        $this->assertFalse($user->fresh()->banned);
    }

    public function test_active_limits_include_pending_work_and_observe_does_not_add_restrictions(): void
    {
        $user = $this->user(); $this->enable(['ai_auto_active_limit' => 1]); $this->evidence();
        $record = $this->service->reserve(10); $this->user(11); $this->evidence(11);
        $this->assertNull($this->service->reserve(11));
        $this->provider->action = 'observe'; $this->service->process($record->id);
        $this->assertSame('completed', $record->fresh()->status); $this->assertNull($this->service->restriction($user));
    }

    public function test_worker_timeout_is_terminal_and_is_not_retried(): void
    {
        $this->user(); $this->enable(); $this->evidence(); $record = $this->service->reserve(10);
        DB::table('v2_subscription_ai_action')->where('id', $record->id)->update(['updated_at' => time() - 181]);
        $this->service->followUp(); $this->service->process($record->id);
        $this->assertSame('failed', $record->fresh()->status); $this->assertSame('worker_timeout', $record->fresh()->outcome); $this->assertCount(0, $this->provider->calls);
    }

    public function test_notification_is_site_branded_queued_once_and_never_contains_model_text_or_credentials(): void
    {
        app()->instance(NotificationSiteContextService::class, new class extends NotificationSiteContextService {
            public function __construct() {}
            public function forUser(User $user, ?\Illuminate\Http\Request $request = null): array { return ['app_name' => 'Site ' . $user->site_id, 'site_id' => $user->site_id]; }
            public function templateValues(array $context, array $values = []): array { return $values + $context; }
            public function dispatchContext(array $context): array { return $context; }
        });
        $this->user(); $this->enable(['ai_auto_notify' => true]); $this->evidence(); $record = $this->active(10, 'temporary_block');
        $this->service->process($record->id); $this->service->followUp();
        $this->assertSame('queued', $record->fresh()->notification_status);
        Bus::assertDispatched(SendEmailJob::class, 1);
        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job): bool {
            $params = (new \ReflectionProperty($job, 'params'))->getValue($job);
            $this->assertSame('synthetic10@example.test', $params['email']); $this->assertSame(2, $params['dispatch_context']['site_id']);
            $this->assertStringContainsString('Site 2', $params['subject']); $this->assertStringNotContainsString('private-token', json_encode($params));
            $this->assertStringNotContainsString('多类触发证据', $params['template_value']['content']);
            return true;
        });
    }

    public function test_unknown_notification_submission_is_not_blindly_sent_again(): void
    {
        $this->user(); $this->enable(); $this->evidence(); $record = $this->active(10, 'temporary_block');
        $record->update(['notification_status' => 'sending', 'notification_started_at' => time() - 181]);
        $this->service->followUp(); $this->assertSame('unknown', $record->fresh()->notification_status);
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    private function independent(string $mode = 'shadow'): \App\Services\SubscriptionAiBehaviorService
    {
        (require base_path('database/migrations/2026_11_02_000002_create_subscription_ai_behavior.php'))->up();
        $this->enable(['ai_auto_mode' => $mode]);
        return app(\App\Services\SubscriptionAiBehaviorService::class);
    }

    public function test_site_scoped_observation_neither_captures_nor_queues_another_sites_account(): void
    {
        $behavior = $this->independent();
        $main = $this->user(10, ['site_id' => null]); $other = $this->user(11);
        $this->enable(['ai_auto_mode' => 'shadow', 'ai_auto_site_ids' => [0]]);
        $request = \Illuminate\Http\Request::create('/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'mihomo', 'REMOTE_ADDR' => '192.0.2.50']);
        $behavior->capture($main, $request, [], 'allowed');
        $behavior->capture($other, $request, [], 'allowed');
        $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $this->assertSame(10, (int) DB::table($behavior::TABLE)->value('user_id'));
        // Even a pre-existing out-of-scope snapshot cannot consume the queue budget.
        $behavior->observe($other, '192.0.2.51', 'mihomo', 'allowed');
        $this->assertNull($this->service->reserve(11));
        $this->assertSame(['queued' => 1], $this->service->tick());
        $this->assertSame([10], SubscriptionAiAction::query()->pluck('user_id')->map(fn($id) => (int) $id)->all());
        $record = SubscriptionAiAction::query()->first(); $this->service->process($record->id);
        $this->assertSame('shadow_proposal_not_executed', $record->fresh()->outcome);
        $this->assertNull($this->service->restriction($main));
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_an_older_policy_editor_cannot_widen_the_saved_site_scope_by_omitting_it(): void
    {
        $this->independent(); $this->enable(['ai_auto_mode' => 'shadow', 'ai_auto_site_ids' => [0]]);
        $oldClient = $this->service->settings(); unset($oldClient['ai_auto_site_ids']);
        $oldClient['ai_auto_hourly_budget'] = 7;
        $saved = $this->service->saveSettings($oldClient, true, 99);
        $this->assertSame([0], $saved['settings']['ai_auto_site_ids']);
        $this->assertSame(7, $saved['settings']['ai_auto_hourly_budget']);
        $this->assertFalse(SubscriptionAiAutomationService::allowsSite($saved['settings'], 2));
    }

    public function test_empty_site_scope_authorizes_nothing_and_invalid_scope_fails_closed(): void
    {
        $none = $this->service->normalize(['ai_auto_site_ids' => []]);
        $this->assertFalse(SubscriptionAiAutomationService::allowsSite($none, 0));
        $this->assertFalse(SubscriptionAiAutomationService::allowsSite($none, 2));
        $this->assertSame([0, 2], $this->service->normalize(['ai_auto_site_ids' => ['2', 0, 2]])['ai_auto_site_ids']);
        foreach (['all', [-1], [true], ['not-a-site'], range(0, 100)] as $invalid) {
            try { $this->service->normalize(['ai_auto_site_ids' => $invalid]); $this->fail('Invalid scope accepted.'); }
            catch (\RuntimeException $error) { $this->assertSame('invalid_site_scope', $error->getMessage()); }
        }
    }

    public function test_narrowing_site_scope_invalidates_a_queued_analysis_before_contacting_the_model(): void
    {
        $this->independent(); $user = $this->user();
        app(\App\Services\SubscriptionAiBehaviorService::class)->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $record = $this->service->reserve($user->id); $this->assertNotNull($record);
        $this->enable(['ai_auto_mode' => 'shadow', 'ai_auto_site_ids' => [0]]);
        $this->service->process($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame('context_changed', $record->fresh()->outcome);
        $this->assertSame([], $this->provider->calls);
    }

    private function historicalBehavior(User $user, int $blocked = 0): void
    {
        $behavior = app(\App\Services\SubscriptionAiBehaviorService::class);
        for ($i = 1; $i <= 8; $i++) {
            $at = time() - $i * 14400;
            DB::table($behavior::TABLE)->insert(['user_id' => $user->id, 'site_id' => $user->site_id,
                'credential_hash' => $behavior->credential($user), 'bucket_at' => intdiv($at, 300) * 300,
                'last_seen_at' => $at, 'snapshot_at' => $at, 'sample' => json_encode(['pulls' => 2, 'ips' => ['synthetic-pseudonym' => 2], 'clients' => ['mihomo' => 2],
                    'outcomes' => $blocked ? ['legacy_blocked' => 2] : ['allowed' => 2], 'capped' => false, 'first_at' => $at, 'last_at' => $at])]);
        }
    }

    public function test_response_capture_does_not_double_count_access_or_expose_hosts_and_respects_site_scope(): void
    {
        $behavior = $this->independent(); $user = $this->user(10, ['site_id' => null]);
        $other = $this->user(11); $this->enable(['ai_auto_mode' => 'shadow', 'ai_auto_site_ids' => [0]]);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $servers = array_map(fn ($id) => ['id' => $id, 'host' => 'private-node.example.test', 'password' => 'private-node-password'], range(1, 30));
        $behavior->captureResponse($user, $servers);
        $identity = ['user_id' => 10, 'site_id' => 0, 'credential_hash' => $behavior->credential($user), 'bucket_at' => intdiv(time(), 300) * 300];
        $key = 'subscription_ai:behavior:' . hash('sha256', json_encode($identity));
        Cache::store('redis')->forget($key . ':snapshot');
        $behavior->captureResponse($user, $servers); $behavior->captureResponse($other, $servers);
        $sample = json_decode(DB::table($behavior::TABLE)->value('sample'), true);
        $this->assertSame(1, $sample['pulls']); $this->assertSame(['allowed' => 1], $sample['outcomes']);
        $this->assertSame(2, $sample['response_count']); $this->assertCount(16, $sample['response_nodes']);
        $this->assertTrue($sample['capped']); $this->assertArrayNotHasKey('', $sample['clients']);
        $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $this->assertStringNotContainsString('private-node', json_encode($sample));
        $evidence = $behavior->evidence($user);
        $this->assertSame(16, $evidence['entry_protection']['candidate_nodes_lower_bound']);
        $this->assertSame('unavailable', $evidence['entry_protection']['status']);
    }

    public function test_shared_machine_signals_can_only_produce_an_observation_not_an_account_penalty(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $record = $this->service->reserve($user->id);
        $evidence = $record->evidence;
        $evidence['entry_protection']['machines'] = [['ref' => 'opaque', 'context' => ['state' => 'fresh']]];
        $record->forceFill(['evidence' => $evidence])->save();
        $this->provider->action = 'temporary_block'; $this->service->process($record->id);
        $payload = json_decode($this->provider->calls[0][1]['content'], true);
        $this->assertSame(['observe'], $payload['allowed_actions']);
        $this->assertArrayHasKey('entry_protection', $payload['evidence']);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame('unsafe_decision', $record->fresh()->outcome);
        $this->assertNull($this->service->restriction($user));
        $this->assertSame('private-token-10', $user->fresh()->token);
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_successful_response_and_access_share_one_snapshot_without_losing_the_first_node_candidates(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $request = \Illuminate\Http\Request::create('/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'mihomo', 'REMOTE_ADDR' => '192.0.2.50']);
        $behavior->capture($user, $request, [], 'allowed', true);
        $this->assertSame(0, DB::table($behavior::TABLE)->count());
        $behavior->captureResponse($user, [['id' => 27]]);
        $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $sample = json_decode(DB::table($behavior::TABLE)->value('sample'), true);
        $this->assertSame(1, $sample['pulls']); $this->assertSame(1, $sample['response_count']);
        $this->assertSame(['27' => 1], $sample['response_nodes']);
    }

    public function test_default_is_independent_shadow_and_disabled_without_an_authorization(): void
    {
        $this->assertSame('shadow', $this->service->settings()['ai_auto_mode']);
        $this->assertFalse($this->service->settings()['ai_auto_enabled']);
    }

    public function test_independent_shadow_queues_normal_access_without_any_legacy_rule_events(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $plugin = new ControlPlugin('subscription_control'); $plugin->setConfig([]);
        $this->assertSame([], $plugin->checkSubscribeAccess([], $user, \Illuminate\Http\Request::create('/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'mihomo', 'REMOTE_ADDR' => '192.0.2.50'])));
        $behavior->captureResponse($user, []);
        $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $this->assertSame(0, DB::table('v2_subscription_control_event')->count());
        $this->assertSame(['queued' => 1], $this->service->tick());
        Bus::assertDispatched(ProcessSubscriptionAiActionJob::class, 1);
        $this->assertSame([], $this->provider->calls);
        $record = SubscriptionAiAction::query()->first(); $this->service->process($record->id);
        $this->assertSame('shadow_proposal_not_executed', $record->fresh()->outcome);
        $this->assertSame('completed', $record->fresh()->status);
        $this->assertNull($this->service->restriction($user));
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_shadow_never_rotates_credentials_sends_mail_or_creates_an_active_protection(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $this->enable(['ai_auto_mode' => 'shadow', 'ai_auto_notify' => true, 'ai_auto_allow_reset_token' => true]);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $this->provider->action = 'reset_token'; $record = $this->service->reserve(10); $this->service->process($record->id);
        $this->assertSame('reset_token', $record->fresh()->action);
        $this->assertSame('shadow_proposal_not_executed', $record->fresh()->outcome);
        $this->assertSame('private-token-10', $user->fresh()->token); $this->assertSame('private-uuid-10', $user->fresh()->uuid);
        $this->assertNull($record->fresh()->expires_at); Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_sparse_or_only_previously_blocked_history_cannot_authorize_independent_enforcement(): void
    {
        $behavior = $this->independent('independent'); $user = $this->user(); $this->historicalBehavior($user, 1);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $this->assertFalse($behavior->evidence($user)['baseline_mature']);
        $record = $this->service->reserve(10); $this->service->process($record->id);
        $this->assertSame('unsafe_decision', $record->fresh()->outcome); $this->assertNull($this->service->restriction($user));
    }

    public function test_independent_decision_uses_behavior_reference_not_rule_hits_or_a_confidence_cutoff(): void
    {
        $behavior = $this->independent('independent'); $user = $this->user(); $this->historicalBehavior($user);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $this->provider->override = ['action' => 'temporary_block', 'duration_seconds' => 60, 'confidence' => 0.7, 'evidence_ids' => [1, 2, 3, 4], 'reason' => '合成测试决定，并非真实风控校准'];
        $record = $this->service->reserve(10); $this->service->process($record->id);
        $this->assertTrue($record->evidence['baseline_mature']); $this->assertFalse($record->evidence['triggered_events_only']);
        $this->assertSame('active', $record->fresh()->status); $this->assertSame(403, $this->service->restriction($user)['status']);
        $this->assertSame(0, DB::table('v2_subscription_control_event')->count());
    }

    public function test_independent_prompt_has_no_raw_identity_network_fingerprints_or_old_rule_score(): void
    {
        $behavior = $this->independent(); $user = $this->user(); $this->historicalBehavior($user);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $record = $this->service->reserve(10); $this->service->process($record->id); $prompt = json_encode($this->provider->calls);
        foreach (['192.0.2.50', 'private-token', 'private-uuid', '@example.test', 'synthetic-pseudonym', 'peak_rule_score'] as $private) $this->assertStringNotContainsString($private, $prompt);
        $this->assertStringContainsString('lower_bounds_only', $prompt);
        $this->assertStringContainsString('unreviewed_history_is_not_verified_legitimate', $prompt);
    }

    public function test_sampling_is_bounded_buffered_and_never_claimed_to_be_exact_accounting(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        for ($i = 0; $i < 20; $i++) $behavior->observe($user, '192.0.2.' . $i, 'mihomo', 'allowed');
        $row = DB::table($behavior::TABLE)->first(); $sample = json_decode($row->sample, true);
        $this->assertSame(1, $sample['pulls']); $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $this->assertTrue($behavior->evidence($user)['sampling']['lower_bounds_only']);
        Cache::put('subscription_ai:behavior_writes:' . intdiv(time(), 60), 60, 120);
        $other = $this->user(11); $behavior->observe($other, '192.0.2.1', 'mihomo', 'allowed');
        $this->assertSame(1, DB::table($behavior::TABLE)->count());
    }

    public function test_history_never_crosses_site_or_credential_boundaries_and_current_access_cannot_train_itself(): void
    {
        $behavior = $this->independent(); $user = $this->user(); $this->historicalBehavior($user);
        $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed'); $this->assertTrue($behavior->evidence($user)['baseline_mature']);
        $user->site_id = 3; $this->assertFalse($behavior->evidence($user)['baseline_mature']); $this->assertSame([], $behavior->evidence($user)['behavior_bucket_ids']);
        $user->site_id = 2; $user->token = 'new-private-token'; $this->assertFalse($behavior->evidence($user)['baseline_mature']);
        $behavior->observe($user, '192.0.2.51', 'mihomo', 'allowed'); $this->assertFalse($behavior->evidence($user)['baseline_mature']);
    }

    public function test_manual_false_positive_feedback_revokes_only_the_reviewed_action_and_is_not_claimed_as_accuracy(): void
    {
        $behavior = $this->independent('independent'); $user = $this->user(); $this->historicalBehavior($user); $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $record = $this->service->reserve(10); $this->service->process($record->id);
        $overview = $this->service->review($record->id, 'false_positive', 99);
        $this->assertSame('revoked', $record->fresh()->status); $this->assertNull($this->service->restriction($user));
        $this->assertSame(1, $overview['evaluation']['reviewed']); $this->assertSame(1, $overview['evaluation']['false_positive']);
        $this->assertTrue($overview['evaluation']['not_a_population_accuracy_rate']);
        $this->assertSame('private-token-10', $user->fresh()->token);
    }

    public function test_manual_review_during_analysis_and_mode_change_prevent_stale_execution(): void
    {
        $behavior = $this->independent(); $user = $this->user(); $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        $record = $this->service->reserve(10);
        $this->provider->during = fn() => $this->enable(['ai_auto_mode' => 'independent']);
        $this->service->process($record->id); $this->assertSame('context_changed', $record->fresh()->outcome);
    }

    public function test_policy_versions_are_immutable_snapshots_and_stale_writes_add_no_version(): void
    {
        $this->independent(); $overview = $this->service->overview(); $revision = $overview['policy_revision'];
        $this->enable(['ai_auto_mode' => 'independent']);
        $saved = json_decode(DB::table('v2_subscription_ai_policy_version')->where('revision', $revision)->value('config'), true);
        $this->assertSame('shadow', $saved['ai_auto_mode']);
        $before = DB::table('v2_subscription_ai_policy_version')->count();
        try { $this->service->saveSettings(['ai_auto_enabled' => true], true, 99, $revision); $this->fail('Stale revision accepted'); }
        catch (\RuntimeException $error) { $this->assertSame('policy_changed', $error->getMessage()); }
        $this->assertSame($before, DB::table('v2_subscription_ai_policy_version')->count());
    }

    public function test_pruning_retains_current_evidence_without_touching_audit_or_accounts(): void
    {
        $behavior = $this->independent(); $user = $this->user(); $this->historicalBehavior($user); $behavior->observe($user, '192.0.2.50', 'mihomo', 'allowed');
        DB::table($behavior::TABLE)->where('bucket_at', '<', time() - 3600)->update(['bucket_at' => DB::raw('bucket_at - 8 * 86400')]);
        $behavior->prune(); $this->assertSame(1, DB::table($behavior::TABLE)->count());
        $this->assertSame(1, User::query()->count()); $this->assertSame(0, SubscriptionAiAction::query()->count());
    }

    private function behaviorKey(User $user): string
    {
        $behavior = app(\App\Services\SubscriptionAiBehaviorService::class);
        return 'subscription_ai:behavior:' . hash('sha256', json_encode(['user_id' => (int) $user->id, 'site_id' => (int) ($user->site_id ?? 0), 'credential_hash' => $behavior->credential($user), 'bucket_at' => intdiv(time(), 300) * 300]));
    }

    public function test_buffer_dimensions_and_global_admissions_remain_bounded(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        for ($i = 0; $i < 40; $i++) $behavior->observe($user, '192.0.2.' . $i, 'client-' . $i, 'allowed');
        $state = Cache::get($this->behaviorKey($user));
        $this->assertCount(16, $state['ips']); $this->assertCount(16, $state['clients']); $this->assertTrue($state['capped']);
        $this->assertSame(40, $state['pulls']);
        Cache::put('subscription_ai:behavior_admissions:' . intdiv(time(), 60), 120, 120);
        $other = $this->user(11); $behavior->observe($other, '192.0.2.1', 'mihomo', 'allowed');
        $this->assertNull(Cache::get($this->behaviorKey($other))); $this->assertSame(1, DB::table($behavior::TABLE)->count());
    }

    public function test_equivalent_ipv6_text_is_one_network_not_an_artificial_behavior_change(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $behavior->observe($user, '2001:db8::1', 'mihomo', 'allowed');
        $behavior->observe($user, '2001:0DB8:0000:0000:0000:0000:0000:0001', 'mihomo', 'allowed');
        $this->assertCount(1, Cache::get($this->behaviorKey($user))['ips']);
    }

    public function test_collector_lock_contention_drops_a_sample_without_blocking_or_executing_ai(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $lock = Cache::lock($this->behaviorKey($user) . ':lock', 3); $this->assertTrue($lock->get());
        try { $behavior->observe($user, '192.0.2.1', 'mihomo', 'allowed'); }
        finally { $lock->release(); }
        $this->assertSame(0, DB::table($behavior::TABLE)->count()); $this->assertSame([], $this->provider->calls);
    }

    public function test_ai_blocked_requests_are_still_observed_as_effects_not_new_rule_hits(): void
    {
        $behavior = $this->independent('independent'); $user = $this->user(); $this->historicalBehavior($user);
        $behavior->observe($user, '192.0.2.1', 'mihomo', 'allowed');
        $this->provider->action = 'temporary_block'; $record = $this->service->reserve(10); $this->service->process($record->id);
        $plugin = new ControlPlugin('subscription_control'); $plugin->setConfig([]);
        try {
            $plugin->checkSubscribeAccess([], $user, \Illuminate\Http\Request::create('/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'mihomo', 'REMOTE_ADDR' => '192.0.2.1']));
            $this->fail('Expected AI protection');
        } catch (\App\Services\Plugin\InterceptResponseException $error) { $this->assertSame(403, $error->getResponse()->getStatusCode()); }
        $this->assertSame(1, Cache::get($this->behaviorKey($user))['outcomes']['ai_blocked']);
        $this->assertSame(0, DB::table('v2_subscription_control_event')->count());
    }

    public function test_disabled_observation_and_staff_access_never_collect_data(): void
    {
        $behavior = $this->independent(); $user = $this->user();
        $this->service->saveSettings(['ai_auto_enabled' => false], false, 99);
        $request = \Illuminate\Http\Request::create('/subscribe', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $behavior->capture($user, $request, [], 'allowed'); $this->assertSame(0, DB::table($behavior::TABLE)->count());
        $this->enable(['ai_auto_mode' => 'shadow']); $user->is_staff = true;
        $behavior->capture($user, $request, [], 'allowed'); $this->assertSame(0, DB::table($behavior::TABLE)->count());
    }

    public function test_confirmed_abuse_windows_are_excluded_from_later_reference_and_feedback_stays_version_scoped(): void
    {
        $behavior = $this->independent(); $user = $this->user(); $this->historicalBehavior($user);
        $behavior->observe($user, '192.0.2.1', 'mihomo', 'allowed');
        $record = $this->service->reserve(10); $this->service->process($record->id);
        $historicalAt = DB::table($behavior::TABLE)->where('bucket_at', '<', time() - 3600)->max('last_seen_at');
        $record->update(['created_at' => $historicalAt]); $this->service->review($record->id, 'confirmed', 99);
        $this->assertFalse($behavior->evidence($user)['baseline_mature']);
        $this->assertSame(1, $this->service->overview()['evaluation']['confirmed']);
        $this->enable(['ai_auto_mode' => 'independent']);
        $this->assertSame(0, $this->service->overview()['evaluation']['reviewed']);
        $this->assertSame('confirmed', $record->fresh()->review_verdict);
    }

    public function test_real_admission_gate_blocks_independent_and_legacy_execution_without_a_benchmark(): void
    {
        $this->independent();
        app()->instance(SubscriptionAiBenchmarkService::class, new SubscriptionAiBenchmarkService());
        foreach (['legacy', 'independent'] as $mode) {
            try { $this->enable(['ai_auto_mode' => $mode]); $this->fail('Execution enabled without admission'); }
            catch (\RuntimeException $error) { $this->assertSame('benchmark_required', $error->getMessage()); }
        }
        $this->assertSame('shadow', $this->service->settings()['ai_auto_mode']);
        $user = $this->user(); $this->evidence();
        $settings = $this->service->settings(); $settings['ai_auto_mode'] = 'legacy';
        DB::table('v2_subscription_ai_policy')->where('id', 1)->update(['config' => json_encode($settings)]);
        $this->assertNull($this->service->reserve($user->id));
        $this->assertSame([], $this->provider->calls);
    }
}
