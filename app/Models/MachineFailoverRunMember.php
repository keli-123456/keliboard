<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverRunMember extends Model
{
    protected $table = 'v2_machine_failover_run_member';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['payload'];
    protected $casts = ['payload' => 'encrypted:array', 'member_id' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
