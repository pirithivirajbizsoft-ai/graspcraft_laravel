<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Shared shape of every model in graspcraft_backend/src/database/models.
 *
 * All of them are declared with the same Sequelize options:
 *
 *     @Table({ timestamps: true, underscored: true, paranoid: true })
 *
 *     @Default(DataType.UUIDV4) @PrimaryKey @Column(DataType.STRING) id
 *
 * which translates to: snake_case created_at / updated_at, soft deletes on
 * deleted_at, and a string primary key holding a UUID that the APPLICATION
 * generates — the columns are `character varying(255)`, not `uuid`, and most
 * carry no database default. Letting Eloquent treat the key as auto-incrementing
 * would insert nothing and fail the NOT NULL.
 *
 * Table names are always set explicitly on the subclass. Several are hyphenated
 * ('fram-img-map', 'images-uploads', …) and none of them match what Eloquent's
 * pluraliser would produce.
 */
abstract class BaseModel extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * NOTE: $snakeAttributes is deliberately left at its default (true).
     *
     * Setting it to false does fix the relation key casing, but it ALSO changes
     * how accessors are registered: a multi-word accessor like emailId() stops
     * matching the `email_id` attribute, so User's decrypting accessors silently
     * stop firing and the API starts returning raw ciphertext for email_id and
     * ph_number. Relation casing is handled in relationsToArray() below instead,
     * which leaves the accessor machinery alone.
     */

    /**
     * Restore the camelCase relation keys that Eloquent snake_cases on the way
     * out.
     *
     * Sequelize emits an include under its declared alias, so the panel reads
     * `roleKioskMap`, `prodCombMap`, `comboUserCommission` and friends. Eloquent
     * would send `role_kiosk_map` and the panel would see undefined.
     *
     * @return array<string, mixed>
     */
    public function relationsToArray(): array
    {
        $serialised = parent::relationsToArray();

        // getRelations() is keyed by the ORIGINAL method name, which is exactly
        // the alias Sequelize would have used.
        $aliases = [];
        foreach (array_keys($this->getRelations()) as $name) {
            $snake = Str::snake($name);
            if ($snake !== $name) {
                $aliases[$snake] = $name;
            }
        }

        if ($aliases === []) {
            return $serialised;
        }

        $out = [];
        foreach ($serialised as $key => $value) {
            $out[$aliases[$key] ?? $key] = $value;
        }

        return $out;
    }

    /**
     * Sequelize's `underscored: true` renames the COLUMNS to created_at /
     * updated_at / deleted_at but leaves the ATTRIBUTE names camelCase, so every
     * model it serialises comes out as `createdAt` / `updatedAt` / `deletedAt`.
     *
     * The Angular panel is written against that: common-table.config.ts and
     * masters.interface.ts read `createdAt` throughout. Emitting Laravel's
     * snake_case keys would blank out the date column on every master screen.
     *
     * This applies ONLY to model serialisation. The raw-SQL report endpoints
     * select `o.*` straight from Postgres and correctly return snake_case there
     * — reports.interface.ts reads `created_at` — so they must not be touched.
     */
    private const TIMESTAMP_ALIASES = [
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
        'deleted_at' => 'deletedAt',
    ];

    /**
     * Serialise dates the way JavaScript's Date.toISOString() does: UTC, with
     * exactly three fractional digits and a literal Z.
     *
     * Sequelize hands dates to JSON.stringify, which produces
     * "2026-08-03T06:50:55.542Z". Laravel's default emits microseconds
     * ("…55.542765Z"). Anything parsing with `new Date()` copes with both, but
     * string comparisons and snapshot tests do not.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach (self::TIMESTAMP_ALIASES as $column => $alias) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$alias] = $attributes[$column];
                unset($attributes[$column]);
            }
        }

        return $attributes;
    }

    /**
     * Sequelize persists only the attributes declared on the model and silently
     * drops anything else, so an over-posted field can never reach a column.
     * $fillable on each subclass reproduces that, listing exactly the columns
     * that model declares.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });

        static::created(function (self $model) {
            /*
             * Sequelize's create() resolves to an instance carrying EVERY declared
             * attribute, so a create response includes the columns that were not
             * supplied — as nulls. Eloquent only holds what was actually set, so
             * those keys would simply be missing from the JSON and the panel would
             * read undefined where it used to read null.
             *
             * $fillable is the declared column list, which is the direct
             * equivalent of Sequelize's model attributes.
             */
            foreach ([...$model->getFillable(), 'deleted_at'] as $column) {
                if (! array_key_exists($column, $model->getAttributes())) {
                    $model->setAttribute($column, null);
                }
            }
        });
    }
}
