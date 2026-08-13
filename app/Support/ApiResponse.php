<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Port of graspcraft_backend/src/utils/response.helper.ts.
 *
 * The envelope is the API contract the Angular panel is written against, and it
 * has one property that is easy to get wrong: a FAILURE is not an HTTP error.
 * In Nest, `errorResponse(...)` is a plain return value from the controller, so
 * it goes out with the normal success status and carries `code: 500` in the
 * body. Every caller in the panel branches on `res.status` / `res.code`, not on
 * the HTTP status. Returning a real 500 here would break error handling
 * everywhere.
 *
 * The status codes themselves also have to match Nest's defaults: @Post replies
 * 201, everything else 200. See ApiResponse::created().
 */
class ApiResponse
{
    /** successResponse() — HTTP 200. Use for GET / PATCH / DELETE routes. */
    public static function success(int $code, string $msg, mixed $data): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'status' => true,
            'msg' => $msg,
            'data' => $data,
        ], 200);
    }

    /**
     * successResponse() — HTTP 201.
     *
     * Nest returns 201 from an @Post handler by default; Laravel returns 200.
     * Every POST route uses this so the status code matches.
     */
    public static function created(int $code, string $msg, mixed $data): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'status' => true,
            'msg' => $msg,
            'data' => $data,
        ], 201);
    }

    /**
     * errorResponse() — the HTTP status stays a success status on purpose; see
     * the class docblock.
     *
     * @param  int  $httpStatus  200 for GET/PATCH/DELETE, 201 for POST, matching
     *                           whatever the corresponding success reply would be.
     */
    public static function error(
        int $code,
        mixed $msg,
        mixed $error = null,
        int $httpStatus = 200,
    ): JsonResponse {
        return response()->json([
            'code' => $code,
            'status' => false,
            'msg' => $msg,
            'error' => self::describeError($error),
        ], $httpStatus);
    }

    /** errorResponse() for a POST route, so the status matches created(). */
    public static function errorCreated(int $code, mixed $msg, mixed $error = null): JsonResponse
    {
        return self::error($code, $msg, $error, 201);
    }

    /**
     * `typeof error === 'string' ? error : error?.message || 'Unknown error'`
     *
     * Note that `null` falls through to 'Unknown error', exactly as in the Node
     * original — several controllers call errorResponse(ER001, EMxxx, null).
     */
    private static function describeError(mixed $error): mixed
    {
        if (is_string($error)) {
            return $error;
        }

        if ($error instanceof \Throwable) {
            return $error->getMessage() ?: 'Unknown error';
        }

        if (is_array($error) && isset($error['message'])) {
            return $error['message'] ?: 'Unknown error';
        }

        if (is_object($error) && isset($error->message)) {
            return $error->message ?: 'Unknown error';
        }

        return 'Unknown error';
    }

    /**
     * formatDateTime() — the Malaysia-local date and time stamped onto orders
     * and image uploads.
     *
     * Returns date as dd-mm-yyyy and time as hh:mm AM/PM. The values are stored
     * as plain strings and are compared as strings by the reports, so the exact
     * formatting matters.
     *
     * @return array{date: string, time: string}
     */
    public static function formatDateTime(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kuala_Lumpur'));

        return [
            'date' => $now->format('d-m-Y'),
            // Intl 'en-US' 2-digit hour with hour12 renders as e.g. "09:05 PM".
            'time' => $now->format('h:i A'),
        ];
    }

    /**
     * generateOrderId() — 'ORD-MMDDHHMMSSmmm', or a millisecond epoch for a
     * suborder.
     *
     * Uses the server's local clock, not the Malaysia timezone, because the Node
     * original uses `new Date()` getters directly.
     */
    public static function generateOrderId(bool $suborder = false): string|int
    {
        if ($suborder) {
            return (int) round(microtime(true) * 1000);
        }

        $now = new \DateTimeImmutable('now');
        $millis = str_pad((string) intdiv((int) $now->format('u'), 1000), 3, '0', STR_PAD_LEFT);

        return 'ORD-'.$now->format('mdHis').$millis;
    }
}
