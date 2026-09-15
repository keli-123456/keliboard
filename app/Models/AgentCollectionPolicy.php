<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCollectionPolicy extends Model
{
    protected $table = 'v2_agent_collection_policy';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'enabled' => 'boolean', 'use_global' => 'boolean', 'platform_enabled' => 'boolean',
        'payment_ids' => 'array', 'fee_bps' => 'integer', 'fee_fixed' => 'integer',
        'settlement_days' => 'integer', 'minimum_withdrawal' => 'integer', 'revision' => 'integer',
    ];
}
