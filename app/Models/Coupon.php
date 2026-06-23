<?php

namespace App\Models;

use App\Services\PlanService;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_SITE = 'site';
    public const SCOPE_AGENT = 'agent';

    protected $table = 'v2_coupon';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'limit_plan_ids' => 'array',
        'limit_period' => 'array',
        'show' => 'boolean',
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
    ];

    public function getLimitPeriodAttribute($value)
    {
        return collect(json_decode((string) $value, true))->map(function ($item) {
            return PlanService::getPeriodKey($item);
        })->toArray();
    }

    public static function normalizeScopeType(?string $scopeType): string
    {
        $scopeType = strtolower(trim((string) $scopeType));

        return in_array($scopeType, [self::SCOPE_GLOBAL, self::SCOPE_SITE, self::SCOPE_AGENT], true)
            ? $scopeType
            : self::SCOPE_GLOBAL;
    }

    public function scopePayload(): array
    {
        $scopeType = self::normalizeScopeType($this->scope_type ?? self::SCOPE_GLOBAL);

        return [
            'scope_type' => $scopeType,
            'site_id' => $scopeType === self::SCOPE_SITE ? ($this->site_id ? (int) $this->site_id : null) : null,
            'agent_user_id' => $scopeType === self::SCOPE_AGENT ? ($this->agent_user_id ? (int) $this->agent_user_id : null) : null,
            'agent_domain_id' => $scopeType === self::SCOPE_AGENT ? ($this->agent_domain_id ? (int) $this->agent_domain_id : null) : null,
        ];
    }
}
