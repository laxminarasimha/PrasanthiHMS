<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setting a price is its own permission. A person who looks after the hospital's lists (services, beds, IPD
 * services) but may not set prices can still rename, file and switch items on and off: the request goes through as
 * long as the price in it is the one already on record. A new item needs someone who may set its price.
 */
class KeepPrices
{
    /** What a price is called on the things that have one. */
    private const FIELDS = ['price', 'daily_charge', 'day_care_charge'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->allows('canSetPrices')) {
            return $next($request);
        }

        $record = collect($request->route()?->parameters() ?? [])->first(fn ($value) => $value instanceof Model);
        foreach (self::FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }
            if ($record === null) {
                abort_if((float) $request->input($field) > 0, 403, 'Only someone allowed to set prices can add this, because it has a price. Ask the owner or an admin.');

                continue;
            }
            abort_if(
                $record->getAttribute($field) !== null && round((float) $request->input($field), 2) !== round((float) $record->getAttribute($field), 2),
                403,
                'You are not allowed to change a price. The rest can be saved with the price left as it is.',
            );
        }

        return $next($request);
    }
}
