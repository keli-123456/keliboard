<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Services\Auth\MailLinkService;
use App\Services\MarketingAutomationService;
use App\Services\NotificationSiteContextService;
use App\Services\SiteNavigationService;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class NotificationSiteContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindTestSettings([
            'app_name' => 'Main Cloud',
            'app_url' => 'https://main.example.test',
            'login_with_mail_link_enable' => 1,
        ]);
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createTicketTables();
    }

    public function test_request_domain_uses_matching_site_branding(): void
    {
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Second Cloud',
            'support_url' => 'https://help.second.example.test',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(NotificationSiteContextService::class)
            ->forRequest(Request::create('https://second.example.test/api/v1/passport/comm/sendEmailVerify', 'POST'));

        $this->assertSame('Second Cloud', $context['app_name']);
        $this->assertSame('https://second.example.test', $context['app_url']);
        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('site', $context['brand_source']);
    }

    public function test_agent_site_overrides_bound_user_site_branding(): void
    {
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Second Cloud',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $agent = $this->createUser('agent@example.test', $site->id);
        $user = $this->createUser('customer@example.test', $site->id);
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
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'setting_scope' => AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => AgentSiteSetting::SCOPE_DEFAULT,
            'site_name' => 'Agent Cloud',
            'support_url' => 'https://t.me/agent_support',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(NotificationSiteContextService::class)->forUser($user);

        $this->assertSame('Agent Cloud', $context['app_name']);
        $this->assertSame('https://agent.example.test', $context['app_url']);
        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame($domain->id, $context['agent_domain_id']);
        $this->assertSame('agent', $context['brand_source']);

        $request = Request::create('https://agent.example.test/sub/token', 'GET');
        $this->assertSame(
            'https://agent.example.test',
            app(SiteNavigationService::class)->urlForSubscription($user, $request)
        );
        $this->assertNull(
            app(WebsiteProxyEndpointService::class)->urlForSubscription($user, $request)
        );
    }

    public function test_agent_without_active_domain_does_not_receive_a_shared_site_entry(): void
    {
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        $agent = $this->createUser('agent-without-domain@example.test', $site->id);
        $user = $this->createUser('customer-without-agent-domain@example.test', $site->id);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(NotificationSiteContextService::class)->forUser($user);
        $request = Request::create('https://second.example.test/sub/token', 'GET');

        $this->assertSame('agent', $context['brand_source']);
        $this->assertNull($context['agent_domain_id']);
        $this->assertNull(
            app(SiteNavigationService::class)->urlForSubscription($user, $request)
        );
        $this->assertNull(
            app(WebsiteProxyEndpointService::class)->urlForSubscription($user, $request)
        );
    }

    public function test_template_and_dispatch_context_expose_the_same_tenant_source_fields(): void
    {
        $context = [
            'app_name' => 'Agent Cloud',
            'app_url' => 'https://agent.example.test',
            'support_name' => '',
            'support_url' => '',
            'brand_source' => 'agent',
            'site_id' => 12,
            'site_domain_id' => 34,
            'agent_user_id' => 56,
            'agent_domain_id' => 78,
            'domain' => 'agent.example.test',
        ];

        $service = app(NotificationSiteContextService::class);
        $this->assertSame('agent', $service->templateValues($context)['tenant_source']);
        $this->assertSame('agent.example.test', $service->templateValues($context)['tenant_domain']);
        $this->assertSame(12, $service->dispatchContext($context)['tenant_site_id']);
        $this->assertSame(56, $service->dispatchContext($context)['tenant_agent_id']);
    }

    public function test_ticket_notification_uses_ticket_agent_context(): void
    {
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        $agent = $this->createUser('agent@example.test', $site->id);
        $user = $this->createUser('customer@example.test', $site->id);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'ticket-agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'setting_scope' => AgentSiteSetting::SCOPE_DOMAIN,
            'setting_key' => (string) $domain->id,
            'site_name' => 'Ticket Agent Cloud',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket = Ticket::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'subject' => 'Need help',
            'status' => Ticket::STATUS_OPENING,
            'level' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(NotificationSiteContextService::class)->forTicket($ticket, $user);

        $this->assertSame('Ticket Agent Cloud', $context['app_name']);
        $this->assertSame('https://ticket-agent.example.test', $context['app_url']);
        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame($domain->id, $context['agent_domain_id']);
    }

    public function test_mail_link_email_uses_user_site_branding(): void
    {
        $dispatcher = $this->bindCapturingDispatcher();
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Second Cloud',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->createUser('customer@example.test', $site->id);

        [$success] = app(MailLinkService::class)->handleMailLink(
            'customer@example.test',
            'dashboard',
            Request::create('https://second.example.test/api/v1/passport/auth/loginWithMailLink', 'POST')
        );

        $this->assertTrue($success);
        $this->assertCount(1, $dispatcher->dispatched);
        $this->assertInstanceOf(SendEmailJob::class, $dispatcher->dispatched[0]);
        $params = $this->emailJobParams($dispatcher->dispatched[0]);

        $this->assertSame('Second Cloud', $params['template_value']['name']);
        $this->assertStringStartsWith('https://second.example.test/', $params['template_value']['link']);
        $this->assertSame($site->id, $params['dispatch_context']['site_id']);
        $this->assertSame('site', $params['dispatch_context']['brand_source']);
    }

    public function test_marketing_template_variables_use_agent_branding(): void
    {
        $site = $this->createSite('second', 'Second Site', 'second.example.test', false);
        $agent = $this->createUser('agent@example.test', $site->id);
        $user = $this->createUser('customer@example.test', $site->id);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent-marketing.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'setting_scope' => AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => AgentSiteSetting::SCOPE_DEFAULT,
            'site_name' => 'Agent Marketing Cloud',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $reflection = new \ReflectionClass(app(MarketingAutomationService::class));
        $method = $reflection->getMethod('buildTemplateVariables');
        $method->setAccessible(true);
        $variables = $method->invoke(app(MarketingAutomationService::class), $user, []);

        $this->assertSame('Agent Marketing Cloud', $variables['app_name']);
        $this->assertSame('https://agent-marketing.example.test', $variables['app_url']);
    }

    private function createSite(string $code, string $name, string $domain, bool $default): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
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

    private function createUser(string $email, ?int $siteId): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'secret',
            'site_id' => $siteId,
            'uuid' => 'uuid-' . md5($email),
            'token' => 'token-' . md5($email),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function emailJobParams(SendEmailJob $job): array
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('params');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function bindCapturingDispatcher(): object
    {
        $dispatcher = new class implements Dispatcher {
            public array $dispatched = [];

            public function dispatch($command)
            {
                $this->dispatched[] = $command;
                return $command;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return null;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        };

        app()->instance(Dispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
