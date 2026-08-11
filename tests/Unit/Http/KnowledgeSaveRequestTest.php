<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Admin\KnowledgeSave;
use App\Models\Knowledge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class KnowledgeSaveRequestTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        Schema::create('v2_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('category');
            $table->string('language');
            $table->string('title');
            $table->text('body');
            $table->boolean('show')->default(true);
            $table->string('scope_type')->default(Knowledge::SCOPE_GLOBAL);
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->unsignedBigInteger('agent_domain_id')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
    }

    public function test_legacy_update_preserves_existing_scope_when_new_fields_are_absent(): void
    {
        $knowledge = Knowledge::query()->create([
            'category' => 'Guide',
            'language' => 'zh',
            'title' => 'Scoped article',
            'body' => 'Body',
            'scope_type' => Knowledge::SCOPE_AGENT,
            'agent_user_id' => 88,
            'agent_domain_id' => 99,
        ]);

        $request = TestableKnowledgeSaveRequest::create('/', 'POST', [
            'id' => $knowledge->id,
            'category' => 'Guide',
            'language' => 'zh',
            'title' => 'Updated title',
            'body' => 'Updated body',
        ]);
        $request->runPrepareForValidation();

        $this->assertSame(Knowledge::SCOPE_AGENT, $request->input('scope_type'));
        $this->assertSame(88, $request->input('agent_user_id'));
        $this->assertSame(99, $request->input('agent_domain_id'));
        $this->assertNull($request->input('site_id'));
    }

    public function test_explicit_global_scope_clears_tenant_identifiers(): void
    {
        $request = TestableKnowledgeSaveRequest::create('/', 'POST', [
            'scope_type' => Knowledge::SCOPE_GLOBAL,
            'site_id' => 7,
            'agent_user_id' => 88,
            'agent_domain_id' => 99,
        ]);
        $request->runPrepareForValidation();

        $this->assertSame(Knowledge::SCOPE_GLOBAL, $request->input('scope_type'));
        $this->assertNull($request->input('site_id'));
        $this->assertNull($request->input('agent_user_id'));
        $this->assertNull($request->input('agent_domain_id'));
    }
}

final class TestableKnowledgeSaveRequest extends KnowledgeSave
{
    public function runPrepareForValidation(): void
    {
        parent::prepareForValidation();
    }
}
