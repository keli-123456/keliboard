<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverBudgetRelease extends Model
{
    protected $table = 'v2_machine_failover_budget_release';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'proof_hash', 'request_key', 'admin_id'];
    protected $casts = ['service_id' => 'integer', 'amount_cents' => 'integer', 'admin_id' => 'integer', 'payload' => 'encrypted:array',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
