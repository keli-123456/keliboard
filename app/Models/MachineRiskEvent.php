<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineRiskEvent extends Model
{
    protected $table = 'v2_machine_risk_event';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['authority_hash', 'episode_key', 'ai_attempt'];
    protected $casts = [
        'machine_id' => 'integer', 'evidence' => 'array', 'analysis' => 'array',
        'detected_at' => 'integer', 'last_reachable_at' => 'integer', 'first_failure_at' => 'integer',
        'recovered_at' => 'integer', 'ai_policy_revision' => 'integer', 'ai_requested_at' => 'integer',
        'ai_finished_at' => 'integer', 'reviewed_at' => 'integer', 'reviewed_by' => 'integer',
        'input_tokens' => 'integer', 'output_tokens' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}
