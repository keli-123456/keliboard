<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_invite_click')) {
            return;
        }

        Schema::create('v2_invite_click', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('invite_code_id')->index();
            $table->string('invite_code', 64)->index();
            $table->integer('inviter_user_id')->index();
            $table->integer('site_id')->nullable()->index();
            $table->char('visitor_hash', 64);
            $table->string('source', 50)->default('direct')->index();
            $table->string('referrer_host', 191)->nullable();
            $table->string('landing_host', 191)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->unsignedInteger('hit_count')->default(1);
            $table->integer('clicked_at')->index();
            $table->integer('last_clicked_at')->index();
            $table->integer('registered_user_id')->nullable()->index();
            $table->integer('converted_at')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index(
                ['invite_code_id', 'visitor_hash', 'last_clicked_at'],
                'v2_invite_click_code_visitor_last_idx'
            );
            $table->index(
                ['inviter_user_id', 'clicked_at'],
                'v2_invite_click_inviter_clicked_idx'
            );
            $table->index(
                ['site_id', 'clicked_at'],
                'v2_invite_click_site_clicked_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_click');
    }
};
