<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for every ported DTO.
 *
 * Nest applies a global ValidationPipe with `{ whitelist: true,
 * forbidNonWhitelisted: false, transform: true }`, which means:
 *
 *   - unknown properties are silently STRIPPED, not rejected;
 *   - a validation failure throws BadRequestException, which Nest renders as
 *     HTTP 400 with its own shape — NOT the successResponse/errorResponse
 *     envelope:
 *
 *         { "message": ["<constraint message>", ...],
 *           "error": "Bad Request",
 *           "statusCode": 400 }
 *
 * Laravel would otherwise answer 422 with `{message, errors}`, so
 * failedValidation() is overridden to reproduce Nest's body and status exactly.
 *
 * Subclasses that need the whitelist behaviour should read $this->validated(),
 * which returns only the declared rules' keys — the direct equivalent of
 * whitelist: true.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // JwtMiddleware is not registered (see its class docblock), so there is
        // no per-request authorisation in the Node app either.
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => array_values($validator->errors()->all()),
            'error' => 'Bad Request',
            'statusCode' => 400,
        ], 400));
    }
}
