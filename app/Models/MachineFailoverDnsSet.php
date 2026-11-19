<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverDnsSet extends Model
{
    protected $table = 'v2_machine_failover_dns_set';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['scope', 'updated_by'];
    protected $casts = ['service_id' => 'integer', 'revision' => 'integer', 'credential_id' => 'integer',
        'credential_revision' => 'integer', 'ttl' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
