<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table): void {
            $table->integer('bonus_amount')->nullable()->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table): void {
            $table->dropColumn('bonus_amount');
        });
    }
};
