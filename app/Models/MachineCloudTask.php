<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineCloudTask extends Model
{
    public const TERMINAL = ['completed', 'failed', 'expired', 'cancelled'];
    protected $table = 'v2_machine_cloud_task';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['request_key'];
    protected $casts = ['automatic' => 'boolean', 'acknowledged' => 'boolean', 'saw_transition' => 'boolean', 'resource_revision' => 'integer',
        'account_revision' => 'integer', 'baseline_event_id' => 'integer', 'dispatched_at' => 'integer', 'provider_confirmed_at' => 'integer',
        'deadline_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'finished_at' => 'integer',
        'resource_id' => 'integer', 'admin_id' => 'integer'];
}
