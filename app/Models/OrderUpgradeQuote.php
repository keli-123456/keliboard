<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderUpgradeQuote extends Model
{
    protected $table = 'v2_order_upgrade_quote';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'integer',
        'target_price' => 'integer',
        'source_paid_basis' => 'integer',
        'time_ratio' => 'float',
        'traffic_ratio' => 'float',
        'base_credit_coeff' => 'float',
        'usage_penalty_coeff' => 'float',
        'credit_cap_amount' => 'integer',
        'min_pay_amount' => 'integer',
        'upgrade_credit_amount' => 'integer',
        'final_pay_amount' => 'integer',
        'snapshot' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id', 'id');
    }

    public function sourcePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'source_plan_id', 'id');
    }

    public function targetPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'target_plan_id', 'id');
    }

    public function upgradeOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'upgrade_quote_id', 'id');
    }
}
