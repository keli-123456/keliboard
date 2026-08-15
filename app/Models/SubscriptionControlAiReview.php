<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionControlAiReview extends Model
{
    protected $table = 'v2_subscription_control_ai_review';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'window_days' => 'integer',
        'event_count' => 'integer',
        'health_score' => 'integer',
        'current_config' => 'array',
        'metrics' => 'array',
        'findings' => 'array',
        'suggestions' => 'array',
        'replay' => 'array',
        'applied_changes' => 'array',
        'admin_id' => 'integer',
        'generated_at' => 'integer',
        'applied_at' => 'integer',
        'rolled_back_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
