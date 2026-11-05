<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineCloudResource extends Model
{
    protected $table = 'v2_machine_cloud_resource';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['machine_authority'];
    protected $casts = ['public_ips' => 'array', 'revision' => 'integer', 'auto_boot' => 'boolean', 'armed' => 'boolean',
        'manual_hold' => 'boolean', 'dns_hold' => 'boolean', 'observed_at' => 'integer', 'last_running_at' => 'integer',
        'offline_since' => 'integer', 'offline_samples' => 'integer', 'last_auto_boot_at' => 'integer',
        'account_id' => 'integer', 'machine_id' => 'integer', 'active_task_id' => 'integer', 'last_task_id' => 'integer'];
}
