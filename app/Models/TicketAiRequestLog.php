<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAiRequestLog extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $table = 'v2_ticket_ai_request_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'ticket_id' => 'integer',
        'suggestion_id' => 'integer',
        'admin_id' => 'integer',
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
        'latency_ms' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'prompt_chars' => 'integer',
        'response_chars' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * @param array<string, mixed> $attributes
     */
    public static function record(array $attributes): self
    {
        $attributes += [
            'status' => self::STATUS_FAILED,
            'scope_type' => 'platform',
            'latency_ms' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'prompt_chars' => 0,
            'response_chars' => 0,
        ];

        if (!array_key_exists('total_tokens', $attributes)) {
            $attributes['total_tokens'] = (int) $attributes['input_tokens'] + (int) $attributes['output_tokens'];
        }

        return self::query()->create($attributes);
    }
}
