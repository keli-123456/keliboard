<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatUserNodeDay extends Model
{
    protected $table = 'v2_stat_user_node_day';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
