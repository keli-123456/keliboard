<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOrderContext extends Model
{
    protected $table = 'v2_agent_order_context';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'pricing_snapshot' => 'array',
        'domain_snapshot' => 'array',
        'payment_snapshot' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(AgentDomain::class, 'agent_domain_id', 'id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(AgentBalanceHold::class, 'hold_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }
}
