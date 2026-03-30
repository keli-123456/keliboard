<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrafficNodeLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minRate = (float) ($this['min_rate'] ?? $this['server_rate'] ?? 0);
        $maxRate = (float) ($this['max_rate'] ?? $this['server_rate'] ?? 0);

        return [
            'server_id' => (int) ($this['server_id'] ?? 0),
            'server_type' => (string) ($this['server_type'] ?? ''),
            'server_name' => (string) ($this['server_name'] ?? ''),
            'u' => (int) ($this['u'] ?? 0),
            'd' => (int) ($this['d'] ?? 0),
            'total' => (int) ($this['total'] ?? 0),
            'record_at' => (int) ($this['record_at'] ?? 0),
            'server_rate' => abs($minRate - $maxRate) < 0.000001 ? $maxRate : null,
            'rate_mixed' => abs($minRate - $maxRate) >= 0.000001,
        ];
    }
}
