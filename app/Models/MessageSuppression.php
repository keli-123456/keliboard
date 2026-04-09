<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageSuppression extends Model
{
    protected $table = 'v2_message_suppression';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'timestamp',
    ];

    public const SCOPE_ALL = 'all';
    public const SCOPE_MARKETING_ONLY = 'marketing_only';
    public const SCOPE_NON_TRANSACTIONAL = 'non_transactional';

    public const REASON_UNSUBSCRIBED = 'unsubscribed';
    public const REASON_MANUAL_BLACKLIST = 'manual_blacklist';
    public const REASON_PERMANENT_FAILURE = 'permanent_failure';
}
