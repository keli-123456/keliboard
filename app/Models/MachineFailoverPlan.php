<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineFailoverPlan extends Model
{
    protected $table = 'v2_machine_failover_plan';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $hidden = ['decision_key'];
    protected $casts = ['policy_id' => 'integer', 'policy_revision' => 'integer', 'observation_id' => 'integer',
        'blockers' => 'array', 'evidence' => 'array', 'created_at' => 'integer', 'closed_at' => 'integer'];
}
