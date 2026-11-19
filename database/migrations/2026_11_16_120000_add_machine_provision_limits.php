<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_machine_cloud_account', fn (Blueprint $table) => $table->json('provision_limits')->nullable());
        Schema::table('v2_machine_failover_member', fn (Blueprint $table) => $table->unsignedInteger('attempt_limit')->nullable());
    }

    public function down(): void
    {
        Schema::table('v2_machine_failover_member', fn (Blueprint $table) => $table->dropColumn('attempt_limit'));
        Schema::table('v2_machine_cloud_account', fn (Blueprint $table) => $table->dropColumn('provision_limits'));
    }
};
