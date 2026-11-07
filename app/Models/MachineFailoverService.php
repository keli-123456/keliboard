<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverService extends Model
{
    protected $table = 'v2_machine_failover_service';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['updated_by'];
    protected $casts = ['revision' => 'integer', 'max_parallel' => 'integer', 'attempt_limit' => 'integer', 'monthly_cap_usd' => 'float',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
