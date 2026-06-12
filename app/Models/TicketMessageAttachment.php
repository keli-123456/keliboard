<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * App\Models\TicketMessageAttachment
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $ticket_message_id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int $created_at
 * @property int $updated_at
 */
class TicketMessageAttachment extends Model
{
    protected $table = 'v2_ticket_message_attachment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
    protected $hidden = [
        'disk',
        'path',
    ];
    protected $appends = [
        'preview_url',
        'thumbnail_url',
        'preview_path',
        'thumbnail_path',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id', 'id');
    }

    public function getPreviewUrlAttribute(): string
    {
        // Ticket images are loaded by browser clients from the same site as the
        // panel. Keep signed preview URLs relative so APP_URL/proxy scheme
        // mismatches cannot produce mixed-content http:// image links.
        return $this->signedPreviewRoute(['id' => $this->id], false);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return $this->signedPreviewRoute(['id' => $this->id, 'variant' => 'thumb'], false);
    }

    public function getPreviewPathAttribute(): string
    {
        return $this->signedPreviewRoute(['id' => $this->id], false);
    }

    public function getThumbnailPathAttribute(): string
    {
        return $this->signedPreviewRoute(['id' => $this->id, 'variant' => 'thumb'], false);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function signedPreviewRoute(array $parameters, bool $absolute): string
    {
        $ttlMinutes = max(1, (int) config('tickets.attachments.preview_ttl', 15));
        return URL::temporarySignedRoute(
            'api.v2.ticket.attachment.preview',
            now()->addMinutes($ttlMinutes),
            $parameters,
            $absolute
        );
    }
}
