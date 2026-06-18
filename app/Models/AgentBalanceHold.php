<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBalanceHold extends Model
{
    protected $table = 'v2_agent_balance_hold';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'captured_at' => 'timestamp',
        'released_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
