<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_trigger_preview', function (Blueprint $t) {
            $t->id(); $t->string('dedup_key', 64)->unique(); $t->string('episode_key', 64)->nullable();
            $t->unsignedBigInteger('service_id')->index(); $t->unsignedBigInteger('member_id'); $t->unsignedBigInteger('policy_id');
            $t->unsignedBigInteger('machine_id'); $t->unsignedInteger('service_revision'); $t->unsignedInteger('policy_revision');
            $t->string('context_hash', 64); $t->string('source_hash', 64); $t->string('decision', 32); $t->text('reasons');
            $t->unsignedInteger('planned_cents')->default(0); $t->string('source_state', 16)->default('unknown');
            $t->unsignedInteger('source_checked_at')->nullable(); $t->unsignedInteger('checked_at'); $t->unsignedInteger('valid_until');
            $t->unsignedInteger('revision')->default(1); $t->unsignedInteger('closed_at')->nullable();
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->index(['service_id', 'member_id', 'closed_at'], 'machine_preview_member_open');
        });
        Schema::create('v2_machine_failover_trigger_event', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('preview_id'); $t->unsignedBigInteger('service_id')->index();
            $t->unsignedBigInteger('member_id'); $t->unsignedBigInteger('policy_id'); $t->unsignedBigInteger('machine_id');
            $t->unsignedInteger('service_revision'); $t->unsignedInteger('policy_revision'); $t->unsignedInteger('revision');
            $t->string('decision', 32); $t->text('reasons'); $t->unsignedInteger('planned_cents'); $t->string('source_state', 16);
            $t->unsignedInteger('source_checked_at')->nullable(); $t->unsignedInteger('checked_at'); $t->unsignedInteger('valid_until');
            $t->unsignedInteger('created_at'); $t->unique(['preview_id', 'revision'], 'machine_preview_transition_once');
        });
    }
    public function down(): void
    {
        foreach (['preview', 'event'] as $name) if (DB::table('v2_machine_failover_trigger_' . $name)->exists())
            throw new RuntimeException('Trigger preview audit must be retained.');
        Schema::dropIfExists('v2_machine_failover_trigger_event'); Schema::dropIfExists('v2_machine_failover_trigger_preview');
    }
};
