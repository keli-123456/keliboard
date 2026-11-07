<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineFailoverObservation extends Model
{
    protected $table = 'v2_machine_failover_observation';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $hidden = ['sample_key', 'context_key'];
    protected $casts = ['policy_id' => 'integer', 'policy_revision' => 'integer', 'checked_at' => 'integer',
        'valid_until' => 'integer', 'observed_at' => 'integer', 'baseline_at' => 'integer',
        'first_failure_at' => 'integer', 'consecutive_failures' => 'integer', 'threshold_reached' => 'boolean'];
}
