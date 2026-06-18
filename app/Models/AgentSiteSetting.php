<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSiteSetting extends Model
{
    protected $table = 'v2_agent_site_setting';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const SCOPE_DEFAULT = 'default';
    public const SCOPE_DOMAIN = 'domain';
    public const KEY_DEFAULT = 'default';

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function setAgentDomainIdAttribute($value): void
    {
        $this->attributes['agent_domain_id'] = $value;
        $this->syncSettingScope($value);
    }

    public function save(array $options = []): bool
    {
        $this->syncSettingScope($this->attributes['agent_domain_id'] ?? null);

        return parent::save($options);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(AgentDomain::class, 'agent_domain_id', 'id');
    }

    private function syncSettingScope($agentDomainId): void
    {
        if ($agentDomainId === null || $agentDomainId === '') {
            $this->setting_scope = self::SCOPE_DEFAULT;
            $this->setting_key = self::KEY_DEFAULT;

            return;
        }

        $this->setting_scope = self::SCOPE_DOMAIN;
        $this->setting_key = (string) $agentDomainId;
    }
}
