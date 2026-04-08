<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
