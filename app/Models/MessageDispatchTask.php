<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageDispatchTask extends Model
{
    protected $table = 'v2_message_dispatch_task';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'payload' => 'array',
        'context' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'scheduled_at' => 'timestamp',
        'available_at' => 'timestamp',
        'reserved_at' => 'timestamp',
        'sent_at' => 'timestamp',
        'last_recovered_at' => 'timestamp',
    ];

    public const STATE_PENDING = 'pending';
    public const STATE_SENDING = 'sending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MarketingRule::class, 'rule_id', 'id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id', 'id');
    }
}
