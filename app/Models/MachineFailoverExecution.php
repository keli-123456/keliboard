<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverExecution extends Model
{
    protected $table = 'v2_machine_failover_execution';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['admin_id', 'request_key', 'context_hash', 'intent_hash', 'payload'];
    protected $casts = ['service_id' => 'integer', 'service_revision' => 'integer', 'admin_id' => 'integer', 'payload' => 'encrypted:array',
        'deadline_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
