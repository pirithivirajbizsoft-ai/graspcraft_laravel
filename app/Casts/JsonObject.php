<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A json column that stays an OBJECT through a PHP round trip.
 *
 * PHP cannot tell `{}` from `[]` once JSON has been decoded into an array, so a
 * request body of `{"response_json": {}}` arrives as an empty PHP array and is
 * written back to Postgres as `[]`. Node keeps it as `{}` end to end, and the
 * column then differs between the two back ends for the same request.
 *
 * This cast decodes to stdClass (so nested objects survive) and, on write,
 * promotes an empty array to an empty object.
 */
class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }

        // The only ambiguous case: an empty PHP array could have been `{}` or
        // `[]`. These columns hold objects, so `{}` is the right reading.
        if ($value === []) {
            return [$key => '{}'];
        }

        if (is_string($value)) {
            return [$key => $value];
        }

        return [$key => json_encode($value)];
    }
}
