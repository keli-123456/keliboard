<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_machine_failover_policy', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->change();
            $table->unsignedInteger('account_revision')->nullable()->change();
            $table->string('provider', 24)->nullable();
            $table->unsignedBigInteger('credential_group_id')->nullable()->index();
            $table->string('catalog_fingerprint', 64)->nullable();
            $table->unsignedInteger('catalog_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('v2_machine_failover_policy')->whereNotNull('credential_group_id')->exists()) throw new RuntimeException('Grouped policies must be reviewed before downgrade.');
        Schema::table('v2_machine_failover_policy', fn (Blueprint $table) => $table->dropIndex(['credential_group_id']));
        Schema::table('v2_machine_failover_policy', function (Blueprint $table) {
            $table->dropColumn(['provider', 'credential_group_id', 'catalog_fingerprint', 'catalog_checked_at']);
            $table->unsignedBigInteger('account_id')->nullable(false)->change();
            $table->unsignedInteger('account_revision')->nullable(false)->change();
        });
    }
};
