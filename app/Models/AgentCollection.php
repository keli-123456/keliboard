<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCollection extends Model
{
    protected $table = 'v2_agent_collection';
    protected $primaryKey = 'agent_user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'payment_ids' => 'array', 'platform_enabled' => 'boolean', 'fee_bps' => 'integer',
        'fee_fixed' => 'integer', 'settlement_days' => 'integer', 'minimum_withdrawal' => 'integer',
    ];
}
