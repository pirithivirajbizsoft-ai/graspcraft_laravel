<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Reproduces NestJS's global ValidationPipe for the ported DTOs.
 *
 * main.ts configures `{ whitelist: true, forbidNonWhitelisted: false,
 * transform: true }`, which means three things the panel depends on:
 *
 *  1. Undeclared properties are STRIPPED, silently. This is not cosmetic — a
 *     combo create that includes `size` has that field dropped before it
 *     reaches the service, so the column stays null. A Laravel controller
 *     passing $request->all() straight through would persist it and diverge.
 *
 *  2. A failure is HTTP 400 with Nest's own body, not the API envelope:
 *         { message: [...], error: 'Bad Request', statusCode: 400 }
 *
 *  3. Message text and ORDER are part of that payload. class-validator applies
 *     decorators bottom-up, so `@IsString() @IsNotEmpty()` reports
 *     "should not be empty" before "must be a string". Properties are reported
 *     in declaration order.
 *
 * Subclasses declare their DTO as a constraint map in the same order and with
 * the same decorators as the TypeScript class, and this base does the rest.
 * Writing plain Laravel `rules()` instead would give different messages, a
 * different status code and no whitelisting.
 */
abstract class ClassValidatorRequest extends FormRequest
{
    /**
     * Property => list of constraints, in DECLARATION order (they are evaluated
     * in reverse, matching decorator order).
     *
     * Supported: 'IsOptional', 'IsNotEmpty', 'IsString', 'IsNumber',
     * 'IsBoolean', 'IsArray', 'ArrayNotEmpty', 'IsPositive', 'IsEmail',
     * ['IsEnum', [...values]], ['IsString', 'each'], ['ValidateIf', callable],
     * ['ValidateNested', ClassValidatorSpec::class].
     *
     * @return array<string, array<int, mixed>>
     */
    abstract protected function constraints(): array;

    public function authorize(): bool
    {
        return true;
    }

    /** Laravel's own rule engine is unused; validation happens in withValidator. */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->constraints() as $property => $constraints) {
                foreach ($this->errorsFor($property, $constraints, $this->input($property)) as $message) {
                    $validator->errors()->add($property, $message);
                }
            }
        });
    }

    /**
     * Only the declared properties, which is what `whitelist: true` produces.
     *
     * @return array<string, mixed>
     */
    public function whitelisted(): array
    {
        $declared = array_keys($this->constraints());

        return array_intersect_key($this->all(), array_flip($declared));
    }

    /**
     * @param  array<int, mixed>  $constraints
     * @return string[]
     */
    private function errorsFor(string $property, array $constraints, mixed $value): array
    {
        $names = array_map(fn ($c) => is_array($c) ? $c[0] : $c, $constraints);

        // @ValidateIf(fn) — when the predicate is false, every other constraint
        // on the property is skipped.
        foreach ($constraints as $constraint) {
            if (is_array($constraint) && $constraint[0] === 'ValidateIf') {
                if (! ($constraint[1])($this->all())) {
                    return [];
                }
            }
        }

        // @IsOptional — an absent or null value skips validation entirely.
        if (in_array('IsOptional', $names, true) && $value === null) {
            return [];
        }

        $messages = [];

        // Decorators are applied bottom-up, so evaluate in reverse.
        foreach (array_reverse($constraints) as $constraint) {
            $name = is_array($constraint) ? $constraint[0] : $constraint;
            $arg = is_array($constraint) ? ($constraint[1] ?? null) : null;

            $message = $this->check($name, $arg, $property, $value);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function check(string $name, mixed $arg, string $property, mixed $value): ?string
    {
        return match ($name) {
            'IsOptional', 'ValidateIf', 'ValidateNested', 'Type' => null,

            /*
             * class-validator's isNotEmpty is `value !== '' && value !== null &&
             * value !== undefined` — an EMPTY ARRAY passes. That matters:
             * CreateRoleDto marks kiosk_ids @IsArray @IsNotEmpty, and the panel
             * legitimately posts `kiosk_ids: []` for a role with no kiosks.
             * Rejecting [] here would break role creation. Use ArrayNotEmpty
             * when an empty array really must be refused.
             */
            'IsNotEmpty' => ($value === null || $value === '')
                ? "{$property} should not be empty"
                : null,

            'ArrayNotEmpty' => (! is_array($value) || $value === [])
                ? "{$property} should not be empty"
                : null,

            'IsString' => $arg === 'each'
                ? (is_array($value) && ! $this->allAre($value, 'is_string')
                    ? "each value in {$property} must be a string"
                    : null)
                : (is_string($value) ? null : "{$property} must be a string"),

            'IsNumber' => is_numeric($value) && ! is_string($value)
                ? null
                : "{$property} must be a number conforming to the specified constraints",

            'IsBoolean' => is_bool($value) ? null : "{$property} must be a boolean value",

            'IsArray' => is_array($value) ? null : "{$property} must be an array",

            'IsPositive' => (is_numeric($value) && $value > 0)
                ? null
                : "{$property} must be a positive number",

            'IsEmail' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL))
                ? null
                : "{$property} must be an email",

            'IsEnum' => $this->checkEnum($arg, $property, $value),

            default => null,
        };
    }

    /** @param array<int, string> $values */
    private function checkEnum(mixed $values, string $property, mixed $value): ?string
    {
        $list = implode(', ', $values);

        if (is_array($value)) {
            // @IsEnum(..., { each: true })
            foreach ($value as $item) {
                if (! in_array($item, $values, true)) {
                    return "each value in {$property} must be one of the following values: {$list}";
                }
            }

            return null;
        }

        return in_array($value, $values, true)
            ? null
            : "{$property} must be one of the following values: {$list}";
    }

    /** @param array<int, mixed> $values */
    private function allAre(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if (! $predicate($value)) {
                return false;
            }
        }

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
