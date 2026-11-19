<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineDnsSetRuntime extends Model
{
    protected $table = 'v2_machine_dns_set_runtime';
    protected $guarded = [];
    protected $primaryKey = 'run_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['lease_hash'];
    protected $casts = ['generation' => 'integer', 'lease_until' => 'integer', 'next_check_at' => 'integer',
        'stop_requested_at' => 'integer', 'finished_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
