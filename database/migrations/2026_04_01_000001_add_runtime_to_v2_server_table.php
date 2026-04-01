<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server') || Schema::hasColumn('v2_server', 'runtime')) {
            return;
        }

        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('runtime')
                ->default('generic')
                ->index()
                ->comment('Server runtime');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_server') || !Schema::hasColumn('v2_server', 'runtime')) {
            return;
        }

        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropIndex(['runtime']);
            $table->dropColumn('runtime');
        });
    }
};
