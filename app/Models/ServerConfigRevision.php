<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerConfigRevision extends Model
{
    protected $table = 'v2_server_config_revision';
    protected $guarded = ['id'];
    protected $hidden = ['snapshot', 'config_hash', 'event_id'];
    protected $casts = ['changes' => 'array'];
}
