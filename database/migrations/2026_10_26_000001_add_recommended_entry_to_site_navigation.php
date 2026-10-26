<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_navigation')) {
            return;
        }
        foreach (['recommended_label' => 120, 'recommended_url' => 1000] as $column => $length) {
            if (!Schema::hasColumn('v2_site_navigation', $column)) {
                Schema::table('v2_site_navigation', function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_site_navigation')) {
            return;
        }
        foreach (['recommended_label', 'recommended_url'] as $column) {
            if (Schema::hasColumn('v2_site_navigation', $column)) {
                Schema::table('v2_site_navigation', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
