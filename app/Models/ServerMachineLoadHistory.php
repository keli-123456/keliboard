<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMachineLoadHistory extends Model
{
    protected $table = 'v2_server_machine_load_history';

    protected $guarded = ['id'];

    protected $casts = [
        'cpu' => 'float',
        'mem_total' => 'integer',
        'mem_used' => 'integer',
        'swap_total' => 'integer',
        'swap_used' => 'integer',
        'disk_total' => 'integer',
        'disk_used' => 'integer',
        'load_status' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id', 'id');
    }
}
