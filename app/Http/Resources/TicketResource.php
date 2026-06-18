<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {  
        $data = [
            "id" => $this['id'],
            "level" => $this['level'],
            "reply_status" => $this['reply_status'],
            "status" => $this['status'],
            "subject" => $this['subject'],
            "message" => array_key_exists('message',$this->additional) ? MessageResource::collection($this['message']) : null,
            "agent" => $this->formatAgent(),
            "agent_domain" => $this->formatAgentDomain(),
            "created_at" => $this['created_at'],
            "updated_at" => $this['updated_at']
        ];
        if(!config('hidden_features.enable_exposed_user_count_fix')) $data['user_id']= $this['user_id'];
        return $data;

    }

    private function formatAgent(): ?array
    {
        if (!$this->resource->relationLoaded('agent')) {
            return null;
        }

        $agent = $this->resource->getRelation('agent');
        if (!$agent) {
            return null;
        }

        return [
            'id' => (int) $agent->id,
            'email' => (string) $agent->email,
        ];
    }

    private function formatAgentDomain(): ?array
    {
        if (!$this->resource->relationLoaded('agentDomain')) {
            return null;
        }

        $domain = $this->resource->getRelation('agentDomain');
        if (!$domain) {
            return null;
        }

        return [
            'id' => (int) $domain->id,
            'domain' => (string) $domain->domain,
        ];
    }
}
