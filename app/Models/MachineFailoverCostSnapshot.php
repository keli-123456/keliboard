<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverCostSnapshot extends Model
{
    protected $table = 'v2_machine_failover_cost_snapshot';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'context_hash', 'admin_id'];
    protected $casts = ['service_id' => 'integer', 'service_revision' => 'integer', 'admin_id' => 'integer', 'payload' => 'encrypted:array',
        'checked_at' => 'integer', 'valid_until' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
