<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * إشعار داخل المنصة (FR-33) على الجدولين القائمين notifications و notification_user (حالة القراءة لكل مستلم).
 * قناة داخلية فقط؛ لا بريد ولا رسائل خارجية.
 */
class PlatformNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['organization_id', 'sender_id', 'type', 'title', 'body', 'data', 'is_system'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_user', 'notification_id', 'user_id')->withPivot('read_at');
    }

    public static function send(?User $recipient, string $type, string $title, string $body, ?string $link = null, array $context = []): void
    {
        if (! $recipient) {
            return;
        }

        $notification = self::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => ['link' => $link] + $context,
            'is_system' => true,
        ]);
        $notification->recipients()->attach($recipient->id);
    }
}
