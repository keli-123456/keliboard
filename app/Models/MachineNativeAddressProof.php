<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineNativeAddressProof extends Model
{
    protected $table = 'v2_machine_native_address_proof';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $hidden = ['payload', 'seal', 'context_hash', 'request_sha256', 'receipt_sha256'];
    protected $casts = ['instance_id' => 'integer', 'payload' => 'encrypted:array', 'issued_at' => 'integer', 'deadline_at' => 'integer', 'valid_until' => 'integer'];
}
