<?php

use App\Http\Middleware\MethodWhitelist;
use App\Http\Middleware\SecureHeaders;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * Reproduces graspcraft_backend/src/main.ts. See that file for the reasoning
 * behind each setting; the comments are carried over where they explain a
 * decision that is easy to undo by accident.
 *
 * The rate limiter itself is defined in AppServiceProvider::boot(), which is
 * where a named limiter has to live.
 */

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // app.setGlobalPrefix('api/')
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * In production nginx terminates TLS and proxies to us on 127.0.0.1, so
         * every request arrives from the same address. Trusting exactly one proxy
         * hop makes the framework read the client address from X-Forwarded-For —
         * without it the rate limiter buckets the entire internet together.
         *
         * Specific addresses and not '*': a blanket trust would let a client
         * spoof its own IP by sending its own X-Forwarded-For header.
         */
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->api(prepend: [
            MethodWhitelist::class,
            SecureHeaders::class,
        ]);

        $middleware->api(append: [
            'throttle:api',
        ]);

        /*
         * JwtMiddleware is ported but deliberately NOT registered — the Node app
         * has its equivalent commented out in app.module.ts, so every endpoint is
         * currently unauthenticated. To turn auth on, add
         * \App\Http\Middleware\JwtMiddleware::class to the prepend list above and
         * audit the Angular app for calls that omit the token.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every controller in the Node app wraps its body in try/catch and
         * returns errorResponse(...) as a NORMAL return value, so a failure goes
         * out with a success HTTP status and `status: false` in the body. The
         * panel branches on that body, never on the HTTP status.
         *
         * This handler is the safety net for anything that escapes a controller
         * (a routing miss, a middleware throw): it keeps the same envelope so the
         * panel never receives a shape it cannot parse.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                // MethodWhitelist emits plain text for 405 in the Node app; keep
                // the framework's own 405 in the same shape.
                if ($status === 405) {
                    return response('Method Not Allowed', 405);
                }

                return ApiResponse::error($status, $e->getMessage() ?: Messages::EM008, $e, $status);
            }

            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        });
    })->create();
