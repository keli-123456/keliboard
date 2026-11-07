<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineReplacementTask extends Model
{
    protected $table = 'v2_machine_replacement_task';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['episode_key', 'source_installation_id', 'source_context_key', 'notice_event', 'recovery_event'];
    protected $casts = ['policy_id' => 'integer', 'policy_revision' => 'integer', 'plan_id' => 'integer',
        'account_id' => 'integer', 'machine_id' => 'integer', 'reserved_monthly_usd' => 'float', 'steps' => 'array',
        'source_recovered_at' => 'integer', 'last_checked_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
