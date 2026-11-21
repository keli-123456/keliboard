<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_subscription_control_event';
    private const INDEX = 'sc_event_user_credential_epoch_idx';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->char('credential_epoch', 64)->nullable();
            $table->index(['user_id', 'credential_epoch', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('credential_epoch');
        });
    }
};
