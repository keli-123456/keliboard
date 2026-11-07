<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineProvisionTask extends Model
{
    public const TERMINAL = ['awaiting_acceptance', 'accepted', 'failed', 'cancelled', 'needs_review'];
    protected $table = 'v2_machine_provision_task';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'request_key', 'callback_hash', 'machine_authority', 'plan_hash', 'admin_id', 'installation_id'];
    protected $casts = ['payload' => 'encrypted:array', 'evidence' => 'array', 'public_ips' => 'array',
        'admin_id' => 'integer', 'account_id' => 'integer', 'account_revision' => 'integer', 'machine_id' => 'integer', 'replacement_id' => 'integer',
        'bootstrap_at' => 'integer', 'dispatched_at' => 'integer', 'next_check_at' => 'integer', 'deadline_at' => 'integer',
        'finished_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
