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
}
