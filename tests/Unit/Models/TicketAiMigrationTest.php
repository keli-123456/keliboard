<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiMigrationTest extends TestCase
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

    public function test_migration_round_trip_owns_and_removes_only_its_schema(): void
    {
        $scopeMigration = $this->migration();
        $requestLogMigration = $this->requestLogMigration();

        $scopeMigration->up();
        $requestLogMigration->up();

        $this->assertTrue(Schema::hasTable('v2_ticket_ai_request_log'));
        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type'));
        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output'));

        $requestLogMigration->down();
        $scopeMigration->down();

        $this->assertFalse(Schema::hasTable('v2_ticket_ai_request_log'));
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type'));
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output'));
    }

    public function test_scope_migration_is_independent_from_a_preexisting_request_log_table(): void
    {
        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->id();
        });

        $migration = $this->migration();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type'));
        $this->assertTrue(Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output'));

        $migration->down();

        $this->assertTrue(Schema::hasTable('v2_ticket_ai_request_log'));
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type'));
    }

    public function test_migration_rejects_preexisting_schema_before_making_partial_changes(): void
    {
        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->id();
        });

        try {
            $this->requestLogMigration()->up();
            $this->fail('Expected the migration to reject pre-existing AI request log schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('v2_ticket_ai_request_log'));
        $this->assertFalse(Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type'));
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_07_13_000001_add_ticket_ai_scope_to_suggestions.php';
    }

    private function requestLogMigration(): object
    {
        return require dirname(__DIR__, 3) . '/database/migrations/2026_07_13_000002_create_ticket_ai_request_logs.php';
    }
}
