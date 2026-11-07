<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_machine_cloud_resource', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->string('external_id', 64)->change();
            $table->string('provider', 20)->default('linode');
            $table->unique(['provider', 'external_id'], 'cloud_provider_identity');
        });
        Schema::table('v2_machine_cloud_task', function (Blueprint $table) {
            $table->string('external_id', 64)->change();
            $table->string('provider', 20)->default('linode');
        });
    }

    public function down(): void
    {
        if (DB::table('v2_machine_cloud_account')->where('provider', '!=', 'linode')->exists()
            || DB::table('v2_machine_cloud_resource')->where('provider', '!=', 'linode')->exists()
            || DB::table('v2_machine_cloud_task')->where('provider', '!=', 'linode')->exists()) throw new \RuntimeException('Cloud provider records must be retained; rollback refused');
        Schema::table('v2_machine_cloud_resource', function (Blueprint $table) {
            $table->dropUnique('cloud_provider_identity'); $table->dropColumn('provider');
            $table->string('external_id', 20)->change(); $table->unique('external_id');
        });
        Schema::table('v2_machine_cloud_task', function (Blueprint $table) { $table->dropColumn('provider'); $table->string('external_id', 20)->change(); });
    }
};
