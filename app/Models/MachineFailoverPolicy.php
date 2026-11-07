<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineFailoverPolicy extends Model
{
    protected $table = 'v2_machine_failover_policy';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['machine_authority', 'dns_scope', 'updated_by'];
    protected $casts = ['revision' => 'integer', 'machine_id' => 'integer', 'account_id' => 'integer',
        'account_revision' => 'integer', 'failure_threshold' => 'integer', 'stale_after_seconds' => 'integer',
        'cooldown_seconds' => 'integer', 'monthly_limit_usd' => 'float', 'daily_limit' => 'integer',
        'dns_credential_id' => 'integer', 'dns_credential_revision' => 'integer', 'isolation_confirmed' => 'boolean',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
