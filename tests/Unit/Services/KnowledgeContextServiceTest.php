<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Knowledge;
use App\Services\AgentSiteContextService;
use App\Services\KnowledgeContextService;
use App\Services\SiteContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class KnowledgeContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private KnowledgeContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        Schema::create('v2_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('scope_type', 20)->nullable();
            $table->unsignedInteger('site_id')->nullable();
            $table->unsignedInteger('agent_user_id')->nullable();
            $table->unsignedInteger('agent_domain_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->service = new KnowledgeContextService(
            Mockery::mock(AgentSiteContextService::class),
            Mockery::mock(SiteContextService::class)
        );

        $this->article('global', Knowledge::SCOPE_GLOBAL);
        $this->article('platform', Knowledge::SCOPE_PLATFORM);
        $this->article('site-1', Knowledge::SCOPE_SITE, 1);
        $this->article('site-2', Knowledge::SCOPE_SITE, 2);
        $this->article('agent-all', Knowledge::SCOPE_AGENT, null, 10);
        $this->article('agent-domain-100', Knowledge::SCOPE_AGENT, null, 10, 100);
        $this->article('agent-domain-101', Knowledge::SCOPE_AGENT, null, 10, 101);
    }

    public function test_site_context_sees_global_and_its_own_articles(): void
    {
        $titles = $this->titles([
            'scope_type' => Knowledge::SCOPE_SITE,
            'site_id' => 1,
        ]);

        $this->assertSame(['global', 'site-1'], $titles);
    }

    public function test_agent_domain_sees_global_agent_wide_and_matching_domain_articles(): void
    {
        $titles = $this->titles([
            'scope_type' => Knowledge::SCOPE_AGENT,
            'agent_user_id' => 10,
            'agent_domain_id' => 100,
        ]);

        $this->assertSame(['agent-all', 'agent-domain-100', 'global'], $titles);
    }

    public function test_platform_context_does_not_receive_site_or_agent_articles(): void
    {
        $titles = $this->titles(['scope_type' => Knowledge::SCOPE_PLATFORM]);

        $this->assertSame(['global', 'platform'], $titles);
    }

    private function titles(array $context): array
    {
        return $this->service->applyScope(Knowledge::query(), $context)
            ->orderBy('title')
            ->pluck('title')
            ->all();
    }

    private function article(
        string $title,
        string $scope,
        ?int $siteId = null,
        ?int $agentUserId = null,
        ?int $agentDomainId = null
    ): void {
        Knowledge::query()->create([
            'title' => $title,
            'scope_type' => $scope,
            'site_id' => $siteId,
            'agent_user_id' => $agentUserId,
            'agent_domain_id' => $agentDomainId,
        ]);
    }
}
