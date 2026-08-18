<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAiConversationEvent extends Model
{
    protected $table = 'v2_ticket_ai_conversation_event';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'conversation_id' => 'integer',
        'ticket_id' => 'integer',
        'source_message_id' => 'integer',
        'suggestion_id' => 'integer',
        'reply_message_id' => 'integer',
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TicketAiConversation::class, 'conversation_id', 'id');
    }
}
