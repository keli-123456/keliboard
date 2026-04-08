<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table): void {
            $table->json('upgrade_to_plan_ids')->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('v2_plan', function (Blueprint $table): void {
            $table->dropColumn('upgrade_to_plan_ids');
        });
    }
};
