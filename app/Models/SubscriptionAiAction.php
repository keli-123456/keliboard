<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAiAction extends Model
{
    protected $table = 'v2_subscription_ai_action';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['credential_hash', 'policy_hash', 'intent_key'];
    protected $casts = [
        'evidence' => 'array', 'authorization' => 'array', 'decision' => 'array', 'user_id' => 'integer', 'site_id' => 'integer',
        'expires_at' => 'integer', 'ended_at' => 'integer', 'checked_at' => 'integer',
        'followup_events' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'reviewed_at' => 'integer', 'reviewed_by' => 'integer', 'policy_revision' => 'integer',
    ];
}
