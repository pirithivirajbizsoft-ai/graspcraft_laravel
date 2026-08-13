<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of the inline method guard in main.ts.
 *
 * Note that the CORS config advertises PUT while this list does not include it,
 * so a real PUT is rejected with 405 even though the preflight allows it. That
 * mismatch exists in the Node app and is reproduced rather than corrected — no
 * route uses PUT, and changing either list is a behaviour change.
 *
 * OPTIONS is allowed through because the CORS preflight has to reach the CORS
 * handler; in Express the cors() middleware ran before this check and answered
 * the preflight itself.
 */
class MethodWhitelist
{
    private const ALLOWED = ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::ALLOWED, true)) {
            return response('Method Not Allowed', 405);
        }

        return $next($request);
    }
}
