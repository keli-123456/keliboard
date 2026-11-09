<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverDnsSlot extends Model
{
    protected $table = 'v2_machine_failover_dns_slot';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['marker'];
    protected $casts = ['set_id' => 'integer', 'member_id' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
