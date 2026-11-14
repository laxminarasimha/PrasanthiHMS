<?php

use Illuminate\Support\Facades\Route;

/*
 * Every address that is not an API call or a real file is handed to the React app,
 * which does its own page routing in the browser. `deploy/build.sh` puts the built
 * app at public/spa.html; in development the Vite server serves it instead.
 */
Route::fallback(function () {
    // An unknown API address is a mistake by the caller, not a page to show.
    // Nor is a missing script or style: answering it with the page would hide that it is gone.
    abort_if(request()->is('api/*', 'assets/*'), 404, 'Not found.');

    $spa = public_path('spa.html');

    abort_unless(is_file($spa), 404, 'The web app has not been built. Run deploy/build.sh, or use the Vite dev server.');

    return response()->file($spa, ['Cache-Control' => 'no-cache']);
});
