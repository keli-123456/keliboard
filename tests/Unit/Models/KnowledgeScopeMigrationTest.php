<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class KnowledgeScopeMigrationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        Schema::create('v2_knowledge', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function test_migration_adds_defaults_and_round_trips_cleanly(): void
    {
        $migration = $this->migration();
        $migration->up();

        foreach (['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('v2_knowledge', $column));
        }

        $id = DB::table('v2_knowledge')->insertGetId([]);
        $this->assertSame('global', DB::table('v2_knowledge')->where('id', $id)->value('scope_type'));

        $migration->down();

        foreach (['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('v2_knowledge', $column));
        }
    }

    public function test_migration_rejects_partial_preexisting_scope_schema(): void
    {
        Schema::table('v2_knowledge', function (Blueprint $table): void {
            $table->unsignedInteger('site_id')->nullable();
        });

        try {
            $this->migration()->up();
            $this->fail('Expected partial knowledge scope schema to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('v2_knowledge', 'site_id'));
        $this->assertFalse(Schema::hasColumn('v2_knowledge', 'scope_type'));
        $this->assertFalse(Schema::hasColumn('v2_knowledge', 'agent_user_id'));
        $this->assertFalse(Schema::hasColumn('v2_knowledge', 'agent_domain_id'));
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_08_11_000004_add_scope_to_v2_knowledge.php';
    }
}
