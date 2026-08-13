<?php

/*
 * Port of the app.enableCors(...) block in main.ts.
 *
 * Browsers reject "*" together with credentials, and a wildcard lets any site
 * call this API from a logged-in user's browser. Set CORS_ORIGINS on each server
 * to that environment's panel origin, comma separated.
 */

/*
 * env() and not config('photocraft.cors_origins'): config files are loaded in
 * alphabetical order, so photocraft.php does not exist yet at this point and the
 * lookup would silently return null — which reads as "not configured" and drops
 * every deployed origin.
 */
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ORIGINS', '')),
)));

if ($origins === []) {
    /*
     * Falling back to "*" here would quietly reintroduce the hole this replaced,
     * so allow only local development origins and make the misconfiguration loud.
     */
    error_log(
        '[cors] CORS_ORIGINS is not set - allowing localhost only. '
        .'Set it to the panel origin (e.g. https://panel.xyz.com) on any deployed server.'
    );

    $origins = ['http://localhost:4200', 'http://localhost:3000'];
}

return [
    'paths' => ['api/*'],

    /*
     * PUT is advertised here but MethodWhitelist rejects it with 405. That
     * mismatch is inherited from main.ts, where the cors() methods list and the
     * inline method guard disagree. No route uses PUT; both lists are left as
     * they are rather than "corrected" in either direction.
     */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
