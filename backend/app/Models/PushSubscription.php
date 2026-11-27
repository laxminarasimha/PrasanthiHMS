<?php

namespace App\Models;

use App\Support\WebPush;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/** One phone or computer a person has asked to be notified on. */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device', 'last_sent_at'])]
class PushSubscription extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Sends one notification to this device. A device that has switched notifications off, or has had the app
     * removed, is forgotten here, so nothing more is sent to it.
     *
     * @param  array<string, mixed>  $message
     */
    public function deliver(array $message): bool
    {
        $status = WebPush::send($this->endpoint, $this->p256dh, $this->auth, $message);
        if ($status >= 200 && $status < 300) {
            $this->forceFill(['last_sent_at' => now()])->save();

            return true;
        }
        if ($status === 404 || $status === 410) {
            $this->delete();

            return false;
        }
        // Only where it was going and what came back: never who or what the notification was about.
        Log::warning('A notification was not accepted.', ['host' => parse_url($this->endpoint, PHP_URL_HOST), 'status' => $status]);

        return false;
    }
}
