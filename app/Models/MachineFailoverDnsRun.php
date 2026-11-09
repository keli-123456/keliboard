<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverDnsRun extends Model
{
    protected $table = 'v2_machine_failover_dns_run';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['admin_id', 'request_key', 'intent_hash', 'context_hash', 'payload'];
    protected $casts = ['payload' => 'encrypted:array', 'set_id' => 'integer', 'service_id' => 'integer', 'set_revision' => 'integer',
        'service_revision' => 'integer', 'cursor' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
