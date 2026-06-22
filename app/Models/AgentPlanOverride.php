<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPlanOverride extends Model
{
    protected $table = 'v2_agent_plan_override';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }
}
