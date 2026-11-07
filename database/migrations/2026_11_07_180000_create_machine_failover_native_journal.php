<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_execution', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('service_id')->index(); $t->unsignedInteger('service_revision');
            $t->unsignedBigInteger('admin_id'); $t->string('request_key', 64)->unique(); $t->string('context_hash', 64);
            $t->string('intent_hash', 64); $t->longText('payload'); $t->string('status', 24)->default('running');
            $t->unsignedInteger('deadline_at'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_execution_member', function (Blueprint $t) {
            $t->id(); $t->uuid('execution_id')->index(); $t->unsignedBigInteger('member_id'); $t->unsignedBigInteger('policy_id');
            $t->unsignedInteger('policy_revision'); $t->unsignedBigInteger('plan_id'); $t->string('episode_key', 64)->unique();
            $t->string('phase', 32)->default('ready'); $t->string('reason', 60)->nullable(); $t->uuid('attempt_id')->nullable();
            $t->longText('payload'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->unique(['execution_id', 'member_id'], 'machine_native_member_once');
        });
        Schema::create('v2_machine_failover_attempt', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('execution_member_id')->index(); $t->unsignedInteger('ordinal');
            $t->string('request_id', 32)->unique(); $t->uuid('task_id')->nullable()->unique(); $t->unsignedBigInteger('account_id')->index();
            $t->unsignedInteger('account_revision'); $t->unsignedInteger('reserved_cents'); $t->string('status', 24)->default('reserved');
            $t->longText('payload'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->unique(['execution_member_id', 'ordinal'], 'machine_native_attempt_once');
        });
    }

    public function down(): void
    {
        if (DB::table('v2_machine_failover_execution')->exists()) throw new RuntimeException('Native failover intents and budget audit must be retained before downgrade.');
        foreach (['attempt', 'execution_member', 'execution'] as $name) Schema::dropIfExists('v2_machine_failover_' . $name);
    }
};
