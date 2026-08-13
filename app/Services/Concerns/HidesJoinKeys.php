<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Hides the foreign/owner keys Eloquent forces into a constrained eager load.
 *
 * Sequelize resolves an include with a SQL JOIN, so `attributes: ['name']`
 * returns literally that one column. Eloquent runs a separate query and matches
 * the rows in PHP, which means the matching key has to be in the SELECT even
 * when the caller does not want it in the payload. Selecting it and hiding it
 * afterwards is the only way to reproduce the Sequelize response exactly.
 *
 * Without this, every constrained include leaks an extra `id` (or `combo_id` /
 * `product_id`) that the Angular panel never received from the Nest API.
 */
trait HidesJoinKeys
{
    /**
     * @param  iterable<mixed>|Model|null  $models
     * @param  array<string, string[]>  $relationKeys  relation name (dot notation
     *                                                 supported) => keys to hide
     */
    protected function hideJoinKeys(mixed $models, array $relationKeys): void
    {
        if ($models === null) {
            return;
        }

        $items = is_iterable($models) ? $models : [$models];

        foreach ($items as $model) {
            if ($model === null) {
                continue;
            }

            foreach ($relationKeys as $path => $keys) {
                $this->hidePath($model, explode('.', $path), $keys);
            }
        }
    }

    /**
     * @param  string[]  $segments
     * @param  string[]  $keys
     */
    private function hidePath(mixed $model, array $segments, array $keys): void
    {
        if ($model === null) {
            return;
        }

        $segment = array_shift($segments);
        $related = $model->{$segment} ?? null;

        if ($related === null) {
            return;
        }

        if ($segments === []) {
            $related->makeHidden($keys);

            return;
        }

        $items = is_iterable($related) ? $related : [$related];

        foreach ($items as $item) {
            $this->hidePath($item, $segments, $keys);
        }
    }
}
