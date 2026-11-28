<?php

namespace App\Models;

use App\Support\WebPush;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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
     * removed, is forgotten here, so nothing more is sent to it. So is one whose keys no notification can ever be
     * locked for: it would only fail again, every time.
     *
     * @param  array<string, mixed>  $message
     */
    public function deliver(array $message): bool
    {
        try {
            $status = WebPush::send($this->endpoint, $this->p256dh, $this->auth, $message);
        } catch (InvalidArgumentException $e) {
            // Words too long to send are not the device's fault: the device is kept.
            if ($this->keysWork()) {
                throw $e;
            }
            report($e);
            $this->delete();

            return false;
        }
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

    /** Whether a notification can be locked for this device's keys at all. Empty words are never too long, so only the keys can fail here. */
    private function keysWork(): bool
    {
        try {
            WebPush::encrypt('', $this->p256dh, $this->auth);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
