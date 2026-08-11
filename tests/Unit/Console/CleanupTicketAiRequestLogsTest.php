<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\CleanupTicketAiRequestLogs;
use App\Models\TicketAiRequestLog;
use App\Models\TicketAiSuggestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class CleanupTicketAiRequestLogsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindTestSettings([
            'ticket_ai_log_retention_days' => 30,
            'ticket_ai_suggestion_retention_days' => 90,
        ]);
        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20);
            $table->string('scope_type', 20)->default('platform');
            $table->integer('latency_ms')->default(0);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('prompt_chars')->default(0);
            $table->integer('response_chars')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            $table->string('status')->default('generated');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function test_cleanup_deletes_only_logs_older_than_configured_retention(): void
    {
        TicketAiRequestLog::record([
            'status' => TicketAiRequestLog::STATUS_SUCCESS,
            'created_at' => time() - (31 * 86400),
            'updated_at' => time() - (31 * 86400),
        ]);
        $recent = TicketAiRequestLog::record([
            'status' => TicketAiRequestLog::STATUS_SUCCESS,
            'created_at' => time() - (29 * 86400),
            'updated_at' => time() - (29 * 86400),
        ]);

        $result = app(CleanupTicketAiRequestLogs::class)->handle();

        $this->assertSame(0, $result);
        $this->assertSame(1, TicketAiRequestLog::query()->count());
        $this->assertNotNull(TicketAiRequestLog::query()->find($recent->id));
    }

    public function test_cleanup_uses_a_separate_retention_period_for_review_drafts(): void
    {
        TicketAiSuggestion::query()->create([
            'ticket_id' => 1,
            'status' => TicketAiSuggestion::STATUS_SUPERSEDED,
            'created_at' => time() - (91 * 86400),
            'updated_at' => time() - (91 * 86400),
        ]);
        $recent = TicketAiSuggestion::query()->create([
            'ticket_id' => 1,
            'status' => TicketAiSuggestion::STATUS_SENT,
            'created_at' => time() - (89 * 86400),
            'updated_at' => time() - (89 * 86400),
        ]);

        $result = app(CleanupTicketAiRequestLogs::class)->handle();

        $this->assertSame(0, $result);
        $this->assertSame(1, TicketAiSuggestion::query()->count());
        $this->assertNotNull(TicketAiSuggestion::query()->find($recent->id));
    }
}
