<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table): void {
            $table->boolean('auto_renew_enable')->default(0)->after('remind_traffic');
            $table->string('auto_renew_period', 32)->nullable()->after('auto_renew_enable');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table): void {
            $table->dropColumn(['auto_renew_enable', 'auto_renew_period']);
        });
    }
};
