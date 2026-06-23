<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingTemplate extends Model
{
    protected $table = 'v2_marketing_template';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'is_system' => 'boolean',
        'variables' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_SITE = 'site';
    public const SCOPE_AGENT = 'agent';

    public static function normalizeScopeType(?string $scopeType): string
    {
        $normalized = strtolower(trim((string) $scopeType));

        return in_array($normalized, [self::SCOPE_SITE, self::SCOPE_AGENT], true)
            ? $normalized
            : self::SCOPE_GLOBAL;
    }

    /**
     * @return array{scope_type: string, site_id: ?int, agent_user_id: ?int, agent_domain_id: ?int}
     */
    public function scopePayload(): array
    {
        $scopeType = self::normalizeScopeType($this->scope_type ?? self::SCOPE_GLOBAL);

        return [
            'scope_type' => $scopeType,
            'site_id' => in_array($scopeType, [self::SCOPE_SITE, self::SCOPE_AGENT], true) && $this->site_id !== null
                ? (int) $this->site_id
                : null,
            'agent_user_id' => $scopeType === self::SCOPE_AGENT && $this->agent_user_id !== null
                ? (int) $this->agent_user_id
                : null,
            'agent_domain_id' => $scopeType === self::SCOPE_AGENT && $this->agent_domain_id !== null
                ? (int) $this->agent_domain_id
                : null,
        ];
    }
}
