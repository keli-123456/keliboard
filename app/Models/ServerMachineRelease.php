<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMachineRelease extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $table = 'v2_server_machine_release';

    protected $guarded = ['id'];

    protected $casts = [
        'size' => 'integer',
        'is_default' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function toAdminPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'component' => (string) $this->component,
            'version' => (string) $this->version,
            'platform' => (string) $this->platform,
            'manifest_path' => (string) $this->manifest_path,
            'archive_path' => (string) $this->archive_path,
            'sha256' => (string) $this->sha256,
            'binary_sha256' => (string) $this->binary_sha256,
            'size' => (int) $this->size,
            'is_default' => (bool) $this->is_default,
            'status' => (string) $this->status,
            'can_delete' => !$this->is_default,
            'created_at' => (int) ($this->created_at ?? 0),
            'updated_at' => (int) ($this->updated_at ?? 0),
        ];
    }
}

