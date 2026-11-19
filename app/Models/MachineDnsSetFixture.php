<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineDnsSetFixture extends Model
{
    protected $table = 'v2_machine_dns_set_fixture';
    protected $guarded = [];
    protected $primaryKey = 'run_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['payload'];
    protected $casts = ['payload' => 'encrypted:array', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
