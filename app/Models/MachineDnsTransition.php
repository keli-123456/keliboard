<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineDnsTransition extends Model
{
    protected $table = 'v2_machine_dns_transition';
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $hidden = ['request_key', 'admin_id'];
    protected $casts = ['dns_id' => 'integer', 'dns_revision' => 'integer', 'policy_revision' => 'integer', 'admin_id' => 'integer',
        'from_instance_id' => 'integer', 'to_instance_id' => 'integer', 'dispatched_at' => 'integer', 'deadline_at' => 'integer',
        'next_check_at' => 'integer', 'readback_attempts' => 'integer', 'last_checked_at' => 'integer', 'confirmed_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
