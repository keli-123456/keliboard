<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'mime' => $this['mime'] ?? null,
            'size' => $this['size'] ?? null,
            'width' => $this['width'] ?? null,
            'height' => $this['height'] ?? null,
            'created_at' => $this['created_at'] ?? null,
        ];
    }
}

