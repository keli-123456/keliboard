<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineRolloutTarget extends Model
{
    protected $table = 'v2_machine_rollout_target';
    protected $guarded = [];
    protected $casts = ['baseline' => 'array', 'samples' => 'array', 'watch' => 'array', 'queued_at' => 'integer', 'observing_at' => 'integer', 'last_sample_at' => 'integer', 'matched_count' => 'integer'];
}
