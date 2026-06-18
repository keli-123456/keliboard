<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDomain extends Model
{
    protected $table = 'v2_agent_domain';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'timestamp',
        'last_checked_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }
}
