<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'v2_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const OWNER_PLATFORM = 'platform';
    public const OWNER_AGENT = 'agent';

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'config' => 'array',
        'enable' => 'boolean',
        'owner_id' => 'integer',
        'owner_domain_id' => 'integer',
    ];
}
