<?php

namespace App\Http\Middleware;

use App\Support\Jwt;
use Closure;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of graspcraft_backend/src/middleware/middleware.ts.
 *
 * NOT REGISTERED, on purpose. In the Node app the equivalent line in
 * app.module.ts is commented out:
 *
 *     configure(consumer: MiddlewareConsumer) {
 *       // consumer.apply(JwtMiddleware).forRoutes('*');
 *     }
 *
 * so every endpoint is currently unauthenticated. This class exists so that
 * behaviour is preserved byte-for-byte during the cutover while enabling auth
 * stays a one-line change: add `JwtMiddleware::class` to the 'api' middleware
 * group in bootstrap/app.php.
 *
 * Before enabling it, audit the Angular app — any call it currently makes
 * without a token will start returning 401.
 */
class JwtMiddleware
{
    /**
     * Routes that skip the check. Kept as the full path including the /api
     * prefix, matching the Node list (which compared against req.baseUrl).
     */
    private const EXCLUDED_ROUTES = [
        '/api/users/user-login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array('/'.ltrim($request->path(), '/'), self::EXCLUDED_ROUTES, true)) {
            return $next($request);
        }

        $authHeader = $request->header('authorization');
        $token = ($authHeader && str_starts_with($authHeader, 'Bearer '))
            ? substr($authHeader, 7)
            : null;

        if (! $token) {
            return response()->json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        try {
            Jwt::verify($token);
        } catch (ExpiredException) {
            // Matches the Node original: HTTP 403 carrying code 401 in the body.
            return response()->json(['code' => 401, 'message' => 'Token has expired.'], 403);
        } catch (\Throwable) {
            return response()->json(['code' => 401, 'message' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}
