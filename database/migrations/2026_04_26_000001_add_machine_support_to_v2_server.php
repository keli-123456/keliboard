<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_machine')) {
            Schema::create('v2_server_machine', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('token', 128)->unique();
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->integer('sort')->default(0)->index();
                $table->unsignedInteger('last_seen_at')->nullable()->index();
                $table->json('load_status')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('v2_server_machine_load_history')) {
            Schema::create('v2_server_machine_load_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('machine_id')->index();
                $table->float('cpu')->nullable();
                $table->unsignedBigInteger('mem_total')->default(0);
                $table->unsignedBigInteger('mem_used')->default(0);
                $table->unsignedBigInteger('swap_total')->default(0);
                $table->unsignedBigInteger('swap_used')->default(0);
                $table->unsignedBigInteger('disk_total')->default(0);
                $table->unsignedBigInteger('disk_used')->default(0);
                $table->json('load_status')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('v2_server')) {
            $needsMachineId = !Schema::hasColumn('v2_server', 'machine_id');
            $needsEnabled = !Schema::hasColumn('v2_server', 'enabled');
            if (!$needsMachineId && !$needsEnabled) {
                return;
            }
            Schema::table('v2_server', function (Blueprint $table) use ($needsMachineId, $needsEnabled) {
                if ($needsMachineId) {
                    $table->unsignedBigInteger('machine_id')->nullable()->after('parent_id')->index();
                }
                if ($needsEnabled) {
                    $table->boolean('enabled')->default(true)->after('show')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_server')) {
            Schema::table('v2_server', function (Blueprint $table) {
                if (Schema::hasColumn('v2_server', 'machine_id')) {
                    $table->dropIndex(['machine_id']);
                    $table->dropColumn('machine_id');
                }
                if (Schema::hasColumn('v2_server', 'enabled')) {
                    $table->dropIndex(['enabled']);
                    $table->dropColumn('enabled');
                }
            });
        }

        Schema::dropIfExists('v2_server_machine_load_history');
        Schema::dropIfExists('v2_server_machine');
    }
};
