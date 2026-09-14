<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_payment', function (Blueprint $table): void {
            $table->unsignedInteger('deleted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Do not silently reactivate archived channels when rolling back.
        \Illuminate\Support\Facades\DB::table('v2_payment')->whereNotNull('deleted_at')->update(['enable' => false]);
        Schema::table('v2_payment', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
