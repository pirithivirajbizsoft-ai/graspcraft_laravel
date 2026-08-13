<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for `app.use(helmet())` in main.ts.
 *
 * Only the headers helmet actually sets on a JSON API are reproduced. helmet's
 * Content-Security-Policy default is aimed at HTML responses and would do
 * nothing useful here, so it is left out — same practical result as the Node
 * app, which serves no markup.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-DNS-Prefetch-Control', 'off');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=15552000; includeSubDomains',
        );

        // expressApp.disable('x-powered-by')
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
