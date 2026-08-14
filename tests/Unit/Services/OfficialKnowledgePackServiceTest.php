<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Knowledge;
use App\Services\OfficialKnowledgePackService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class OfficialKnowledgePackServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private OfficialKnowledgePackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        Schema::create('v2_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('language', 10)->default('zh-CN');
            $table->string('category');
            $table->string('title');
            $table->text('body');
            $table->integer('sort')->nullable();
            $table->boolean('show')->default(true);
            $table->string('scope_type', 20)->default('global');
            $table->unsignedInteger('site_id')->nullable();
            $table->unsignedInteger('agent_user_id')->nullable();
            $table->unsignedInteger('agent_domain_id')->nullable();
            $table->string('source_type', 20)->default('custom');
            $table->string('source_key', 191)->nullable()->unique();
            $table->string('source_version', 32)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->integer('source_synced_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->service = new OfficialKnowledgePackService(
            dirname(__DIR__, 3) . '/database/knowledge-packs/client-guides'
        );
    }

    public function test_sync_installs_the_pack_idempotently(): void
    {
        $first = $this->service->sync();
        $this->assertSame('官方使用文档', $first['status']['title']);
        $this->assertSame(12, $first['summary']['created']);
        $this->assertSame(0, $first['summary']['updated']);
        $this->assertSame(12, Knowledge::query()->where('source_type', Knowledge::SOURCE_OFFICIAL)->count());

        $second = $this->service->sync();
        $this->assertSame(12, $second['summary']['unchanged']);
        $this->assertSame(12, $second['status']['summary']['current']);
        $this->assertSame(
            '快速开始',
            Knowledge::query()->where('source_key', 'client-guides/keliboard-getting-started')->value('title')
        );
    }

    public function test_sync_preserves_locally_modified_official_articles(): void
    {
        $this->service->sync();
        $knowledge = Knowledge::query()->where('source_key', 'client-guides/keliboard-getting-started')->firstOrFail();
        $knowledge->update(['title' => '本地维护的标题']);

        $status = $this->service->status();
        $this->assertSame(1, $status['summary']['local_modified']);

        $result = $this->service->sync();
        $this->assertSame(1, $result['summary']['skipped_local_modified']);
        $this->assertSame('本地维护的标题', $knowledge->fresh()->title);
    }
}
