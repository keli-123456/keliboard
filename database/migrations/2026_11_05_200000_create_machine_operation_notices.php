<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_operation_notice_policy', function (Blueprint $table) {
            $table->string('scope', 10)->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('updated_at');
        });
        foreach (['cloud', 'dns'] as $scope) DB::table('v2_machine_operation_notice_policy')->insert([
            'scope' => $scope, 'enabled' => false, 'revision' => 1, 'updated_at' => time(),
        ]);
        Schema::create('v2_machine_operation_notice', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('scope', 10);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->string('episode', 36)->nullable()->index();
            $table->string('phase', 20);
            $table->string('context_hash', 64);
            $table->unsignedInteger('policy_revision');
            $table->text('snapshot');
            $table->string('status', 20);
            $table->string('delivery_reason', 40)->nullable();
            $table->unsignedInteger('claimed_at')->nullable();
            $table->unsignedInteger('next_dispatch_at');
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['scope', 'source_id', 'phase'], 'machine_notice_source');
            $table->index(['status', 'next_dispatch_at'], 'machine_notice_pending');
        });
        Schema::table('v2_machine_dns_binding', function (Blueprint $table) {
            $table->string('notice_episode', 36)->nullable();
            $table->unsignedInteger('notice_last_alert_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_machine_dns_binding', fn (Blueprint $table) => $table->dropColumn(['notice_episode', 'notice_last_alert_at']));
        Schema::dropIfExists('v2_machine_operation_notice');
        Schema::dropIfExists('v2_machine_operation_notice_policy');
    }
};
