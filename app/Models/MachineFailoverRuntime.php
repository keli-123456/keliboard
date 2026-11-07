<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverRuntime extends Model
{
    protected $table = 'v2_machine_failover_runtime';
    protected $primaryKey = 'execution_id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $hidden = ['ticket_hash', 'stop_requested_by'];
    protected $casts = ['revision' => 'integer', 'generation' => 'integer', 'lease_until' => 'integer', 'next_check_at' => 'integer',
        'next_dispatch_at' => 'integer', 'last_member_id' => 'integer', 'stop_requested_at' => 'integer',
        'stop_requested_by' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
