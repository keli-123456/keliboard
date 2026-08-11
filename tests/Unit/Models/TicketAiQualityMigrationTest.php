<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiQualityMigrationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        Schema::create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function test_quality_migration_round_trip(): void
    {
        $migration = $this->migration();
        $migration->up();

        foreach (['quality_rating', 'feedback_reason', 'edit_ratio', 'knowledge_hit_count', 'knowledge_gap'] as $column) {
            $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', $column));
        }

        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'quality_rating'));
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'knowledge_gap'));
    }

    public function test_quality_migration_refuses_partial_schema(): void
    {
        Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->string('quality_rating')->nullable();
        });

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_08_11_000005_add_quality_fields_to_ticket_ai_suggestions.php';
    }
}
