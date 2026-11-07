<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverRun extends Model
{
    protected $table = 'v2_machine_failover_run';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['admin_id', 'request_key', 'intent_hash', 'context_hash', 'payload'];
    protected $casts = ['payload' => 'encrypted:array', 'service_id' => 'integer', 'service_revision' => 'integer',
        'tick' => 'integer', 'attempts' => 'integer', 'reserved_usd' => 'float', 'finished_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
