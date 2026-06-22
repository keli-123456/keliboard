<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_plan_override')) {
            Schema::create('v2_site_plan_override', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('plan_id')->index();
                $table->string('display_name', 120)->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['site_id', 'plan_id'], 'uniq_site_plan_override');
            });
        }

        if (!Schema::hasTable('v2_agent_plan_override')) {
            Schema::create('v2_agent_plan_override', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('plan_id')->index();
                $table->string('display_name', 120)->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['agent_user_id', 'plan_id'], 'uniq_agent_plan_override');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_plan_override');
        Schema::dropIfExists('v2_site_plan_override');
    }
};
