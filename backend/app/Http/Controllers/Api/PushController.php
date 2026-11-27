<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\WebPush;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A person switching notifications on or off for the phone or computer in their hand.
 * Everything here is about the signed-in person's own devices; nobody can add or remove another person's.
 */
class PushController extends Controller
{
    /** The most devices kept for one person. Adding another lets go of the one used longest ago. */
    private const MOST_DEVICES = 8;

    /** This site's public key, which the device needs before it can ask to be notified. */
    public function key(): JsonResponse
    {
        return response()->json(['key' => WebPush::publicKey()]);
    }

    /**
     * Notes the device as the signed-in person's. A device belongs to one person at a time: whoever is signed in
     * on it now. So a shared phone never shows one person's notifications to the next.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', function (string $name, mixed $value, Closure $fail) {
                if (! is_string($value) || ! WebPush::allowed($value)) {
                    $fail('This browser’s notifications cannot be used with the app.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255', fn (string $name, mixed $value, Closure $fail) => strlen(WebPush::decode((string) $value)) === 65 || $fail('This browser’s notifications cannot be used with the app.')],
            'keys.auth' => ['required', 'string', 'max:255', fn (string $name, mixed $value, Closure $fail) => strlen(WebPush::decode((string) $value)) === 16 || $fail('This browser’s notifications cannot be used with the app.')],
        ]);

        $user = $request->user();
        PushSubscription::updateOrCreate(['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])], [
            'user_id' => $user->id,
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'device' => self::deviceName((string) $request->userAgent()),
        ]);
        PushSubscription::where('user_id', $user->id)->latest('updated_at')->latest('id')->get()->slice(self::MOST_DEVICES)->each->delete();

        return response()->json(['on' => true], 201);
    }

    /** Notifications switched off on this device, or the person signing out of it. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->delete();

        return response()->json(null, 204);
    }

    /** Sends a notification to this device now, so the person can see that it arrives. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $device = PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->first();
        if (! $device) {
            return response()->json(['sent' => false, 'message' => 'Notifications are not switched on for this device. Switch them on first.'], 422);
        }

        $sent = $device->deliver([
            'title' => 'Notifications are on',
            'body' => 'This is how a new appointment will reach you.',
            'url' => '/account',
            'tag' => 'test',
            'kind' => 'test',
        ]);

        return response()->json([
            'sent' => $sent,
            'message' => $sent
                ? 'Sent. It should appear on this device in a few seconds.'
                : ($device->exists ? 'The notification could not be sent just now. Please try again in a minute.' : 'This device had stopped accepting notifications. Switch them on again.'),
        ]);
    }

    /** A plain name for the device, so the list of a person's devices can be read. */
    private static function deviceName(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android phone',
            str_contains($agent, 'Windows') => 'Windows computer',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux computer',
            default => 'Other device',
        };
    }
}
