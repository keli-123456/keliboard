<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_cloud_identity', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('account_id')->unique(); $t->string('provider', 20);
            $t->string('credential_key', 64); $t->string('identity_key', 64)->nullable()->index();
            $t->unsignedInteger('verified_at')->nullable(); $t->unsignedInteger('checked_at');
        });
        Schema::create('v2_machine_credential_health', function (Blueprint $t) {
            $t->id(); $t->string('provider', 20); $t->unsignedBigInteger('account_id')->index();
            $t->string('credential_key', 64); $t->string('kind', 40); $t->string('scope', 16); $t->string('scope_key', 64);
            $t->string('last_source_key', 64);
            $t->string('state', 16); $t->string('operation', 16); $t->unsignedInteger('http_status')->nullable();
            $t->unsignedInteger('failures')->default(1); $t->unsignedInteger('blocked_until')->nullable();
            $t->unsignedInteger('observed_at'); $t->unsignedInteger('cleared_at')->nullable();
            $t->unique(['provider', 'kind', 'scope_key', 'credential_key'], 'machine_credential_health_rule');
        });
        Schema::create('v2_machine_credential_event', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('account_id')->index(); $t->string('credential_key', 64);
            $t->string('source_key', 64)->unique(); $t->string('kind', 40); $t->string('scope', 16);
            $t->string('state', 16); $t->string('operation', 16); $t->unsignedInteger('http_status')->nullable();
            $t->unsignedInteger('blocked_until')->nullable(); $t->unsignedInteger('occurred_at');
        });
    }
    public function down(): void
    {
        if (DB::table('v2_machine_credential_event')->exists() || DB::table('v2_machine_cloud_identity')->exists()
            || DB::table('v2_machine_credential_health')->exists()) {
            throw new RuntimeException('Credential health and account identity audit must be retained before downgrade.');
        }
        foreach (['credential_event', 'credential_health', 'cloud_identity'] as $table) Schema::dropIfExists('v2_machine_' . $table);
    }
};
