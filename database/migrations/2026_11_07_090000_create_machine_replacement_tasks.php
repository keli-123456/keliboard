<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_replacement_task', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedInteger('policy_revision');
            $table->unsignedBigInteger('plan_id');
            $table->string('episode_key', 64)->unique();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('machine_id');
            $table->string('source_installation_id', 32);
            $table->string('source_context_key', 64);
            $table->string('task_id', 36)->unique();
            $table->decimal('reserved_monthly_usd', 8, 2);
            $table->string('status', 40);
            $table->string('reason', 50)->nullable();
            $table->string('notice_event', 36);
            $table->unsignedInteger('source_recovered_at')->nullable();
            $table->string('recovery_event', 36)->nullable();
            $table->text('steps');
            $table->unsignedInteger('last_checked_at')->default(0)->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::table('v2_machine_provision_task', function (Blueprint $table) {
            $table->unsignedBigInteger('replacement_id')->nullable()->unique();
        });
        DB::table('v2_machine_operation_notice_policy')->insertOrIgnore([
            'scope' => 'provision', 'enabled' => false, 'revision' => 1, 'updated_at' => time(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('v2_machine_replacement_task')->exists()) {
            throw new RuntimeException('Replacement task audit must be retained before downgrade');
        }
        DB::table('v2_machine_operation_notice')->where('scope', 'provision')->delete();
        DB::table('v2_machine_operation_notice_policy')->where('scope', 'provision')->delete();
        Schema::table('v2_machine_provision_task', fn (Blueprint $table) => $table->dropUnique(['replacement_id']));
        Schema::table('v2_machine_provision_task', fn (Blueprint $table) => $table->dropColumn('replacement_id'));
        Schema::dropIfExists('v2_machine_replacement_task');
    }
};
