<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineOwnedDns extends Model
{
    protected $table = 'v2_machine_owned_dns';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['marker'];
    protected $casts = ['policy_id' => 'integer', 'revision' => 'integer', 'credential_id' => 'integer',
        'credential_revision' => 'integer', 'active_instance_id' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
