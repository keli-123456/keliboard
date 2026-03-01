<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this['id'],
            "ticket_id" => $this['ticket_id'],
            "is_me" => $this['is_from_user'],
            "is_auto_reply" => (bool) ($this['is_auto_reply'] ?? false),
            "auto_reply_rule" => $this['auto_reply_rule'] ?? null,
            "message"  => $this["message"],
            "attachments" => TicketMessageAttachmentResource::collection($this['attachments'] ?? []),
            "created_at" => $this['created_at'],
            "updated_at" => $this['updated_at']
        ];
    }
}
