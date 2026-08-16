<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionControlCaseReview extends Model
{
    public const STATUS_WATCHING = 'watching';
    public const STATUS_CONFIRMED_LEAK = 'confirmed_leak';
    public const STATUS_FALSE_POSITIVE = 'false_positive';
    public const STATUS_CLEARED = 'cleared';
    public const STATUSES = [
        self::STATUS_WATCHING,
        self::STATUS_CONFIRMED_LEAK,
        self::STATUS_FALSE_POSITIVE,
        self::STATUS_CLEARED,
    ];

    protected $table = 'v2_subscription_control_case_review';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'evidence_snapshot' => 'array',
        'suspicion_score' => 'integer',
        'baseline_last_trigger_at' => 'integer',
        'reviewed_at' => 'integer',
        'admin_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}