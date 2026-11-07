<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_cost_snapshot', function (Blueprint $t) {
            $t->bigIncrements('sequence'); $t->uuid('id')->unique(); $t->unsignedBigInteger('service_id')->index(); $t->unsignedInteger('service_revision');
            $t->unsignedBigInteger('admin_id'); $t->string('context_hash', 64); $t->longText('payload');
            $t->unsignedInteger('checked_at'); $t->unsignedInteger('valid_until'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_budget_release', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('attempt_id')->unique(); $t->unsignedBigInteger('service_id')->index();
            $t->unsignedBigInteger('admin_id'); $t->string('request_key', 64)->unique(); $t->unsignedInteger('amount_cents');
            $t->string('proof_kind', 32); $t->string('proof_hash', 64); $t->longText('payload');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
    }
    public function down(): void
    {
        foreach (['cost_snapshot', 'budget_release'] as $name) if (DB::table('v2_machine_failover_' . $name)->exists()) throw new RuntimeException('Failover cost and release evidence must be retained.');
        Schema::dropIfExists('v2_machine_failover_budget_release'); Schema::dropIfExists('v2_machine_failover_cost_snapshot');
    }
};
