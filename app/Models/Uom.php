<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit of Measurement master for the Inventory module.
 *
 * New functionality, not a port of anything in graspcraft_backend. A UOM is
 * either a base unit (base_unit is null) or a derived unit that converts to
 * its base unit via conversion_factor ("1 of this unit = conversion_factor
 * base units"). Only one level of derivation is supported, matching the
 * inventory reference this module was built from.
 */
class Uom extends BaseModel
{
    protected $table = 'inventory_uoms';

    protected $fillable = [
        'id',
        'name',
        'uom_short',
        'base_unit',
        'conversion_factor',
        'is_active',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'conversion_factor' => 1,
        'is_active' => true,
    ];

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'base_unit');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(Uom::class, 'base_unit');
    }

    public function scopeBaseUnits($query)
    {
        return $query->whereNull('base_unit');
    }

    /** The id of this unit's base unit family — itself, if it has none. */
    public function resolveBaseUnitId(): string
    {
        return $this->base_unit ?? $this->id;
    }
}
