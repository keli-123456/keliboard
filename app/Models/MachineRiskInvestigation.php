<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineRiskInvestigation extends Model
{
    protected $table = 'v2_machine_risk_investigation';
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'event_id' => 'integer', 'policy_revision' => 'integer', 'started_at' => 'integer', 'deadline_at' => 'integer',
        'checked_at' => 'integer', 'next_check_at' => 'integer', 'last_probe_at' => 'integer', 'recovery_samples' => 'integer',
        'ai_queued_at' => 'integer', 'ai_next_at' => 'integer', 'ai_deadline_at' => 'integer', 'observation' => 'array', 'notices' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(MachineRiskEvent::class, 'event_id');
    }
}
