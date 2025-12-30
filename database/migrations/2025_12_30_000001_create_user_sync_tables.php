<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_sync_states')) {
            Schema::create('user_sync_states', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->integer('group_id')->nullable()->index();
                $table->string('uuid', 64)->default('');
                $table->integer('speed_limit')->default(0);
                $table->integer('device_limit')->default(0);
                $table->boolean('available')->default(false)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_sync_events')) {
            Schema::create('user_sync_events', function (Blueprint $table) {
                $table->bigIncrements('id'); // revision
                $table->unsignedBigInteger('user_id')->index();

                $table->integer('old_group_id')->nullable()->index();
                $table->integer('group_id')->nullable()->index();

                $table->boolean('old_available')->default(false);
                $table->boolean('available')->default(false);

                $table->string('old_uuid', 64)->nullable();
                $table->string('uuid', 64)->default('');

                $table->integer('speed_limit')->default(0);
                $table->integer('device_limit')->default(0);

                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        // Bootstrap states for existing users (best-effort, only if empty).
        if (Schema::hasTable('v2_user')) {
            $count = (int) DB::table('user_sync_states')->count();
            if ($count === 0) {
                $driver = DB::getDriverName();
                if ($driver === 'mysql') {
                    DB::statement("
                        INSERT INTO user_sync_states (user_id, group_id, uuid, speed_limit, device_limit, available, created_at, updated_at)
                        SELECT
                            u.id,
                            u.group_id,
                            COALESCE(u.uuid, ''),
                            COALESCE(u.speed_limit, 0),
                            COALESCE(u.device_limit, 0),
                            CASE
                                WHEN u.group_id IS NULL THEN 0
                                WHEN u.banned = 1 THEN 0
                                WHEN u.expired_at IS NOT NULL AND u.expired_at > 0 AND u.expired_at < UNIX_TIMESTAMP() THEN 0
                                WHEN u.transfer_enable IS NULL THEN 0
                                WHEN (COALESCE(u.u, 0) + COALESCE(u.d, 0)) >= u.transfer_enable THEN 0
                                ELSE 1
                            END,
                            NOW(),
                            NOW()
                        FROM v2_user u
                    ");
                } else {
                    // Fallback: do nothing for non-mysql drivers.
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sync_events');
        Schema::dropIfExists('user_sync_states');
    }
};

