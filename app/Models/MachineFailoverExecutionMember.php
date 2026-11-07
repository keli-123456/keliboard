<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverExecutionMember extends Model
{
    protected $table = 'v2_machine_failover_execution_member';
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'episode_key'];
    protected $casts = ['member_id' => 'integer', 'policy_id' => 'integer', 'policy_revision' => 'integer', 'plan_id' => 'integer',
        'payload' => 'encrypted:array', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
