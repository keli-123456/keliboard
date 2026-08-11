<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_admin_operation_task', function (Blueprint $table) {
            $table->unsignedInteger('dismissed_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('v2_admin_operation_task', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
