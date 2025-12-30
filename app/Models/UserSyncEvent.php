<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSyncEvent extends Model
{
    protected $table = 'user_sync_events';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'old_group_id',
        'group_id',
        'old_available',
        'available',
        'old_uuid',
        'uuid',
        'speed_limit',
        'device_limit',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'old_group_id' => 'integer',
        'group_id' => 'integer',
        'old_available' => 'boolean',
        'available' => 'boolean',
        'speed_limit' => 'integer',
        'device_limit' => 'integer',
    ];
}

