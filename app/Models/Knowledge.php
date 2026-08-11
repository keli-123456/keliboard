<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_SITE = 'site';
    public const SCOPE_AGENT = 'agent';
    public const SCOPE_TYPES = [
        self::SCOPE_GLOBAL,
        self::SCOPE_PLATFORM,
        self::SCOPE_SITE,
        self::SCOPE_AGENT,
    ];


    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'show' => 'boolean',
        'site_id' => 'integer',
        'agent_user_id' => 'integer',
        'agent_domain_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
