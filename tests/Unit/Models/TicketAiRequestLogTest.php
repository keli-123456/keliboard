<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\TicketAiRequestLog;
use App\Models\TicketAiSuggestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiRequestLogTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();

        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id')->nullable();
            $table->integer('suggestion_id')->nullable();
            $table->integer('admin_id')->nullable();
            $table->string('status', 20);
            $table->string('error_code', 40)->nullable();
            $table->string('scope_type', 20)->default('platform');
            $table->integer('site_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            $table->string('provider_host')->nullable();
            $table->string('model')->nullable();
            $table->integer('latency_ms')->default(0);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('prompt_chars')->default(0);
            $table->integer('response_chars')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function test_record_normalizes_a_successful_scoped_request(): void
    {
        $log = TicketAiRequestLog::record([
            'status' => TicketAiRequestLog::STATUS_SUCCESS,
            'scope_type' => 'site',
            'site_id' => 3,
            'latency_ms' => 125,
            'input_tokens' => 80,
            'output_tokens' => 30,
        ]);

        $this->assertSame(TicketAiRequestLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(110, $log->total_tokens);
        $this->assertSame(3, $log->site_id);
        $this->assertSame(125, $log->latency_ms);
        $this->assertSame(0, $log->prompt_chars);
    }

    public function test_numeric_fields_and_suggestion_structured_output_are_cast(): void
    {
        $log = TicketAiRequestLog::record([
            'status' => TicketAiRequestLog::STATUS_FAILED,
            'input_tokens' => '12',
            'output_tokens' => '8',
            'total_tokens' => '25',
            'agent_user_id' => '9',
        ]);

        $suggestion = new TicketAiSuggestion(['structured_output' => 1]);

        $this->assertSame(25, $log->total_tokens);
        $this->assertSame(9, $log->agent_user_id);
        $this->assertTrue($suggestion->structured_output);
    }
}
