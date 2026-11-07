<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_service', function (Blueprint $t) {
            $t->id(); $t->string('name', 80); $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('max_parallel')->default(1); $t->unsignedInteger('attempt_limit')->default(2);
            $t->decimal('monthly_cap_usd', 10, 2)->default(0); $t->unsignedBigInteger('updated_by');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_member', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('service_id')->index(); $t->unsignedBigInteger('policy_id')->unique();
            $t->unsignedBigInteger('machine_id')->unique(); $t->unsignedInteger('policy_revision');
            $t->string('name', 80); $t->unsignedInteger('priority'); $t->boolean('enabled')->default(true);
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_run', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('service_id')->index(); $t->unsignedInteger('service_revision');
            $t->unsignedBigInteger('admin_id'); $t->string('request_key', 64)->unique(); $t->string('intent_hash', 64);
            $t->string('mode', 16)->default('simulation'); $t->string('status', 24); $t->string('reason', 60)->nullable();
            $t->string('context_hash', 64); $t->longText('payload'); $t->unsignedInteger('tick')->default(0);
            $t->unsignedInteger('attempts')->default(0); $t->decimal('reserved_usd', 10, 2)->default(0);
            $t->unsignedInteger('finished_at')->nullable(); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_run_member', function (Blueprint $t) {
            $t->id(); $t->uuid('run_id')->index(); $t->unsignedBigInteger('member_id');
            $t->string('phase', 32); $t->string('reason', 60)->nullable(); $t->longText('payload');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->unique(['run_id', 'member_id'], 'machine_failover_run_member_once');
        });
        Schema::create('v2_machine_failover_action', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('run_id')->index(); $t->unsignedBigInteger('member_id');
            $t->string('kind', 24); $t->string('status', 24); $t->longText('receipt')->nullable();
            $t->unsignedInteger('virtual_at');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->unique(['run_id', 'member_id', 'kind'], 'machine_failover_action_once');
        });
    }

    public function down(): void
    {
        if (DB::table('v2_machine_failover_run')->exists()) throw new RuntimeException('Failover simulation audit must be retained before downgrade.');
        foreach (['action', 'run_member', 'run', 'member', 'service'] as $name) Schema::dropIfExists('v2_machine_failover_' . $name);
    }
};
