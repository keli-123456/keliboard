<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverAttempt extends Model
{
    protected $table = 'v2_machine_failover_attempt';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['request_id', 'payload'];
    protected $casts = ['execution_member_id' => 'integer', 'ordinal' => 'integer', 'account_id' => 'integer', 'account_revision' => 'integer',
        'reserved_cents' => 'integer', 'payload' => 'encrypted:array', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
