<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageAttachmentResource extends JsonResource
{
    private function attribute(string $key): mixed
    {
        return data_get($this->resource, $key);
    }

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
            'preview_url' => $this->attribute('preview_url'),
            'thumbnail_url' => $this->attribute('thumbnail_url'),
            'preview_path' => $this->attribute('preview_path'),
            'thumbnail_path' => $this->attribute('thumbnail_path'),
            'created_at' => $this['created_at'] ?? null,
        ];
    }
}

