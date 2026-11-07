<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineCloudGroup extends Model
{
    protected $table = 'v2_machine_cloud_group';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['name_key'];
    protected $casts = ['revision' => 'integer'];
}
