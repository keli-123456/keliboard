<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAiSuggestion extends Model
{
    public const STATUS_GENERATED = 'generated';
    public const STATUS_INSERTED = 'inserted';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_SENT = 'sent';

    protected $table = 'v2_ticket_ai_suggestion';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'knowledge_refs' => 'array',
        'matched_knowledge' => 'array',
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
        'structured_output' => 'boolean',
        'needs_human' => 'boolean',
        'confidence' => 'float',
        'edited' => 'boolean',
        'inserted_at' => 'integer',
        'discarded_at' => 'integer',
        'sent_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }
}
