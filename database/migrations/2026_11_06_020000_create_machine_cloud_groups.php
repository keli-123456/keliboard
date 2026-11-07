<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_cloud_group', function (Blueprint $table) {
            $table->id(); $table->string('name', 60); $table->string('name_key', 64)->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::table('v2_machine_cloud_account', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->unsignedInteger('group_revision')->default(1);
        });
    }
    public function down(): void
    {
        Schema::table('v2_machine_cloud_account', function (Blueprint $table) {
            $table->dropIndex(['group_id']); $table->dropColumn(['group_id', 'group_revision']);
        });
        Schema::dropIfExists('v2_machine_cloud_group');
    }
};
