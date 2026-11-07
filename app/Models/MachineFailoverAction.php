<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverAction extends Model
{
    protected $table = 'v2_machine_failover_action';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['receipt'];
    protected $casts = ['receipt' => 'encrypted:array', 'member_id' => 'integer', 'virtual_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
