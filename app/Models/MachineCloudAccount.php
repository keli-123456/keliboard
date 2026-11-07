<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineCloudAccount extends Model
{
    protected $table = 'v2_machine_cloud_account';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['credentials'];
    protected $casts = ['credentials' => 'encrypted:array', 'revision' => 'integer', 'last_sync_at' => 'integer',
        'group_id' => 'integer', 'group_revision' => 'integer'];
}
