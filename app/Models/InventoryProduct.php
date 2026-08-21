<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stockable "Product" of the Inventory module.
 *
 * Named InventoryProduct (not Product) because App\Models\Product already
 * exists — it is the unrelated, pre-existing photo-studio product used by
 * the combo/order flow and must not be touched. This is new functionality,
 * not a port of anything in graspcraft_backend, and deliberately carries no
 * category — see CLAUDE.md task scope.
 */
class InventoryProduct extends BaseModel
{
    protected $table = 'inventory_products';

    protected $fillable = [
        'id',
        'product_code',
        'name',
        'description',
        'uom_id',
        'unit_price',
        'cost_price',
        'current_stock',
        'min_stock',
        'low_stock_alert',
        'is_stockable',
        'is_active',
        'average_cost',
        'last_purchase_cost',
        'last_stock_update',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'current_stock' => 'decimal:3',
        'min_stock' => 'decimal:3',
        'low_stock_alert' => 'decimal:3',
        'is_stockable' => 'boolean',
        'is_active' => 'boolean',
        'average_cost' => 'decimal:2',
        'last_purchase_cost' => 'decimal:2',
        'last_stock_update' => 'datetime',
    ];

    protected $attributes = [
        'unit_price' => 0,
        'current_stock' => 0,
        'is_stockable' => true,
        'is_active' => true,
    ];

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    /**
     * low_stock_alert is the primary threshold; min_stock is the fallback
     * used only when no alert threshold has been set.
     */
    public function isLowStock(): bool
    {
        if (! $this->is_stockable) {
            return false;
        }

        if ($this->low_stock_alert !== null && (float) $this->low_stock_alert > 0) {
            return (float) $this->current_stock <= (float) $this->low_stock_alert;
        }

        if ($this->min_stock !== null && (float) $this->min_stock > 0) {
            return (float) $this->current_stock <= (float) $this->min_stock;
        }

        return false;
    }
}
