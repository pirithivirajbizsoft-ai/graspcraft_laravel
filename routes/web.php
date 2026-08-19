<?php

use Illuminate\Support\Facades\Route;

/*
 * Serves the built Angular app (public/index.html, placed there by
 * `npm run build` in graspcraft_frontend - see angular.json) for the SPA shell
 * itself and every one of its client-side routes (/login, /dashboard,
 * /devotees, ...). The Angular router takes over from there.
 *
 * Static build output (JS/CSS bundles, /assets/*) is served directly by the
 * web server before this ever runs - public/.htaccess only rewrites to
 * index.php when the request doesn't match a real file or directory.
 *
 * '(?!api\/)' excludes anything under /api so a mistyped or removed API route
 * still gets Laravel's normal JSON 404 instead of this HTML shell.
 */
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api\/).*$');
