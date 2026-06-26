<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'token')) {
            return;
        }

        $column = DB::selectOne(
            "select character_maximum_length
               from information_schema.columns
              where table_schema = database()
                and table_name = 'v2_user'
                and column_name = 'token'
              limit 1"
        );

        if ((int) ($column->character_maximum_length ?? 0) < 64) {
            DB::statement('alter table v2_user modify `token` varchar(64) not null');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'token')) {
            return;
        }

        $tooLong = DB::table('v2_user')
            ->whereRaw('char_length(`token`) > 32')
            ->exists();

        if (!$tooLong) {
            DB::statement('alter table v2_user modify `token` char(32) not null');
        }
    }
};
