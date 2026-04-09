<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpamRegistrationCandidate extends Model
{
    protected $table = 'v2_spam_registration_candidate';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'freeze_applied' => 'boolean',
        'is_login_frozen' => 'boolean',
        'reason_codes' => 'array',
        'evaluation_snapshot' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'candidate_since' => 'timestamp',
        'last_evaluated_at' => 'timestamp',
        'preserved_at' => 'timestamp',
        'restored_at' => 'timestamp',
        'soft_deleted_at' => 'timestamp',
        'noted_at' => 'timestamp',
    ];

    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_PRESERVED = 'preserved';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_SOFT_DELETED = 'soft_deleted';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
