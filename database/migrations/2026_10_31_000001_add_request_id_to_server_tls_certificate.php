<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_server_tls_certificate') && !Schema::hasColumn('v2_server_tls_certificate', 'request_id')) {
            Schema::table('v2_server_tls_certificate', fn (Blueprint $table) => $table->string('request_id', 32)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server_tls_certificate', 'request_id')) {
            Schema::table('v2_server_tls_certificate', fn (Blueprint $table) => $table->dropColumn('request_id'));
        }
    }
};
