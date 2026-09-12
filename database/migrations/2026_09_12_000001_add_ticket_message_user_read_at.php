<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $baseline = (int) DB::table('v2_ticket_message')->max('id');
        if (!Schema::hasColumn('v2_ticket_message', 'user_read_at')) {
            Schema::table('v2_ticket_message', function (Blueprint $table) {
                // Existing messages predate read tracking; zero is the legacy-read sentinel.
                $table->unsignedInteger('user_read_at')->nullable()->default(0);
                $table->index(['ticket_id', 'user_read_at', 'id'], 'ticket_message_user_unread');
            });
        }
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            // New messages are unread without changing any reply writer or historical row.
            $table->unsignedInteger('user_read_at')->nullable()->default(null)->change();
        });
        // Replies inserted while the default changes must not inherit the legacy-read sentinel.
        DB::table('v2_ticket_message')->where('id', '>', $baseline)->where('user_read_at', 0)->update(['user_read_at' => null]);
    }

    public function down(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->dropIndex('ticket_message_user_unread');
            $table->dropColumn('user_read_at');
        });
    }
};
