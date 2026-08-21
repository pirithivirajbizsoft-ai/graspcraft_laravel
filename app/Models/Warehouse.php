<?php

namespace App\Models;

/**
 * Warehouse / stock-location master for the Inventory module.
 *
 * New functionality, not a port of anything in graspcraft_backend.
 */
class Warehouse extends BaseModel
{
    protected $table = 'inventory_warehouses';

    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
