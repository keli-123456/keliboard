<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_knowledge', function (Blueprint $table) {
            // Allow longer language codes and bigger article bodies
            $table->string('language', 16)->change();
            $table->mediumText('body')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_knowledge', function (Blueprint $table) {
            $table->char('language', 5)->change();
            $table->text('body')->change();
        });
    }
};
