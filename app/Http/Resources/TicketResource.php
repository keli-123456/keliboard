<?php

namespace App\Http\Resources;

use ArrayAccess;
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
            "id" => $this->value('id'),
            "level" => $this->value('level'),
            "reply_status" => $this->value('reply_status'),
            "status" => $this->value('status'),
            "subject" => $this->value('subject'),
            "message" => array_key_exists('message', $this->additional) ? MessageResource::collection($this->value('message')) : null,
            "agent" => $this->formatAgent(),
            "agent_domain" => $this->formatAgentDomain(),
            "created_at" => $this->value('created_at'),
            "updated_at" => $this->value('updated_at')
        ];
        if(!config('hidden_features.enable_exposed_user_count_fix')) $data['user_id']= $this->value('user_id');
        return $data;

    }

    private function formatAgent(): ?array
    {
        $agent = $this->relatedValue('agent');
        if ($agent !== null) {
            return $this->formatAgentLike($agent);
        }

        if (!is_object($this->resource) || !method_exists($this->resource, 'relationLoaded')) {
            return null;
        }

        if (!$this->resource->relationLoaded('agent')) {
            return null;
        }

        $agent = $this->resource->getRelation('agent');
        if (!$agent) {
            return null;
        }

        return $this->formatAgentLike($agent);
    }

    private function formatAgentDomain(): ?array
    {
        $domain = $this->relatedValue('agent_domain');
        if ($domain !== null) {
            return $this->formatAgentDomainLike($domain);
        }

        if (!is_object($this->resource) || !method_exists($this->resource, 'relationLoaded')) {
            return null;
        }

        if (!$this->resource->relationLoaded('agentDomain')) {
            return null;
        }

        $domain = $this->resource->getRelation('agentDomain');
        if (!$domain) {
            return null;
        }

        return $this->formatAgentDomainLike($domain);
    }

    private function formatAgentLike(mixed $agent): ?array
    {
        if (!is_array($agent) && !is_object($agent)) {
            return null;
        }

        return [
            'id' => (int) $this->valueFrom($agent, 'id'),
            'email' => (string) $this->valueFrom($agent, 'email'),
        ];
    }

    private function formatAgentDomainLike(mixed $domain): ?array
    {
        if (!is_array($domain) && !is_object($domain)) {
            return null;
        }

        return [
            'id' => (int) $this->valueFrom($domain, 'id'),
            'domain' => (string) $this->valueFrom($domain, 'domain'),
        ];
    }

    private function relatedValue(string $key): mixed
    {
        if (is_array($this->resource) || $this->resource instanceof ArrayAccess) {
            return $this->value($key);
        }

        if (is_object($this->resource) && !method_exists($this->resource, 'relationLoaded')) {
            return $this->value($key);
        }

        return null;
    }

    private function value(string $key): mixed
    {
        return $this->valueFrom($this->resource, $key);
    }

    private function valueFrom(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        if ($source instanceof ArrayAccess && isset($source[$key])) {
            return $source[$key];
        }

        if (is_object($source) && isset($source->{$key})) {
            return $source->{$key};
        }

        if (is_object($source) && method_exists($source, 'getAttribute')) {
            return $source->getAttribute($key);
        }

        return null;
    }
}
