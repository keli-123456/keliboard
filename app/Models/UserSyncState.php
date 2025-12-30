<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSyncState extends Model
{
    protected $table = 'user_sync_states';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'group_id',
        'uuid',
        'speed_limit',
        'device_limit',
        'available',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'group_id' => 'integer',
        'speed_limit' => 'integer',
        'device_limit' => 'integer',
        'available' => 'boolean',
    ];
}

