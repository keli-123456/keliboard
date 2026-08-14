<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class KnowledgeSourceMigrationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        Schema::create('v2_knowledge', fn (Blueprint $table) => $table->id());
    }

    public function test_migration_adds_source_metadata_and_round_trips(): void
    {
        $migration = $this->migration();
        $migration->up();

        foreach (['source_type', 'source_key', 'source_version', 'source_hash', 'source_synced_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('v2_knowledge', $column));
        }

        $id = DB::table('v2_knowledge')->insertGetId([]);
        $this->assertSame('custom', DB::table('v2_knowledge')->where('id', $id)->value('source_type'));

        $migration->down();
        foreach (['source_type', 'source_key', 'source_version', 'source_hash', 'source_synced_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('v2_knowledge', $column));
        }
    }

    public function test_migration_rejects_partial_preexisting_source_schema(): void
    {
        Schema::table('v2_knowledge', fn (Blueprint $table) => $table->string('source_key')->nullable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_08_14_000001_add_source_metadata_to_v2_knowledge.php';
    }
}
