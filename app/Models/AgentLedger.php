<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLedger extends Model
{
    public $timestamps = false;

    protected $table = 'v2_agent_ledger';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id', 'id');
    }
}
