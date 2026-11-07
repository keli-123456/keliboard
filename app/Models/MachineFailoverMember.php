<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverMember extends Model
{
    protected $table = 'v2_machine_failover_member';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = ['service_id' => 'integer', 'policy_id' => 'integer', 'machine_id' => 'integer', 'policy_revision' => 'integer',
        'priority' => 'integer', 'enabled' => 'boolean', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
