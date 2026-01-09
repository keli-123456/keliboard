<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_risk_event')) {
            return;
        }

        Schema::create('v2_risk_event', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 32)->comment('事件类型：subscribe/login_success/login_failed 等');
            $table->integer('user_id')->nullable()->comment('关联用户ID');
            $table->char('token_hash', 64)->nullable()->comment('订阅token sha256（不存明文）');
            $table->string('ip', 45)->nullable()->comment('客户端IP（支持IPv6）');
            $table->text('ua')->nullable()->comment('User-Agent（可能被截断）');
            $table->char('ua_hash', 64)->nullable()->comment('UA sha256（用于聚合）');
            $table->string('client_name', 32)->nullable()->comment('客户端名称（从flag/UA识别）');
            $table->string('client_version', 32)->nullable()->comment('客户端版本（从flag/UA识别）');
            $table->string('route', 128)->nullable()->comment('路由名/标识（避免记录含token的path）');
            $table->smallInteger('status_code')->nullable()->comment('HTTP状态码（可选）');
            $table->text('meta')->nullable()->comment('附加信息（JSON）');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index(['created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['token_hash', 'created_at']);
            $table->index(['ua_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_risk_event');
    }
};

