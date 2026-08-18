<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketAiConversation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_WAITING_USER = 'waiting_user';
    public const STATUS_HUMAN_REQUIRED = 'human_required';
    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'v2_ticket_ai_conversation';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
        'auto_reply_count' => 'integer',
        'follow_up_count' => 'integer',
        'low_confidence_count' => 'integer',
        'failure_count' => 'integer',
        'last_source_message_id' => 'integer',
        'last_reply_message_id' => 'integer',
        'handoff_at' => 'integer',
        'last_activity_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketAiConversationEvent::class, 'conversation_id', 'id');
    }
}
