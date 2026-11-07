<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_review_check', function (Blueprint $t) {
            $t->bigIncrements('sequence'); $t->uuid('id')->unique(); $t->unsignedBigInteger('member_id')->index(); $t->uuid('attempt_id')->nullable()->index();
            $t->unsignedBigInteger('admin_id'); $t->string('context_hash', 64); $t->longText('payload');
            $t->unsignedInteger('checked_at'); $t->unsignedInteger('valid_until'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_review_action', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('member_id')->index(); $t->uuid('attempt_id')->nullable()->index();
            $t->unsignedBigInteger('admin_id'); $t->string('operation', 24); $t->string('request_key', 64)->unique();
            $t->longText('payload'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
    }
    public function down(): void
    {
        foreach (['check', 'action'] as $name) if (DB::table('v2_machine_failover_review_' . $name)->exists()) throw new RuntimeException('Failover review evidence must be retained.');
        Schema::dropIfExists('v2_machine_failover_review_action'); Schema::dropIfExists('v2_machine_failover_review_check');
    }
};
