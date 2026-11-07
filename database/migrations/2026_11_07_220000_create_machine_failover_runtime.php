<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_runtime', function (Blueprint $t) {
            $t->uuid('execution_id')->primary(); $t->unsignedInteger('revision')->default(0); $t->unsignedInteger('generation')->default(0);
            $t->string('state', 24)->default('idle'); $t->string('ticket_hash', 64)->nullable();
            $t->unsignedInteger('lease_until')->nullable(); $t->unsignedInteger('next_check_at')->default(0);
            $t->unsignedInteger('next_dispatch_at')->default(0); $t->unsignedBigInteger('last_member_id')->default(0);
            $t->unsignedInteger('stop_requested_at')->nullable(); $t->unsignedBigInteger('stop_requested_by')->nullable();
            $t->string('reason', 60)->nullable(); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->index(['state', 'next_check_at'], 'machine_native_runtime_due');
        });
        Schema::create('v2_machine_failover_runtime_event', function (Blueprint $t) {
            $t->id(); $t->uuid('execution_id')->index(); $t->unsignedInteger('generation');
            $t->string('kind', 24); $t->string('reason', 60)->nullable(); $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('request_key', 64)->nullable()->unique(); $t->string('intent_hash', 64)->nullable(); $t->unsignedInteger('created_at');
        });
    }
    public function down(): void
    {
        foreach (['runtime', 'runtime_event'] as $name) if (DB::table('v2_machine_failover_' . $name)->exists()) throw new RuntimeException('Native runtime audit must be retained.');
        Schema::dropIfExists('v2_machine_failover_runtime_event'); Schema::dropIfExists('v2_machine_failover_runtime');
    }
};
