<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Shared by StockMovement and StockBalance: both point at one of three
 * different tables (inventory_products, the existing products, or the
 * existing combo) via a polymorphic item_type/item_id pair rather than a
 * single FK — see the morph map registered in AppServiceProvider::boot().
 *
 * The three source tables use different "name" columns (InventoryProduct:
 * name, Product: product_name, Combo: combo_name), so getItemLabelAttribute
 * normalises that into one field the API can return regardless of type.
 */
trait ReferencesStockItem
{
    public function item(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'item_type', 'item_id');
    }

    public function getItemLabelAttribute(): ?string
    {
        $item = $this->item;

        if (! $item) {
            return null;
        }

        return $item->name ?? $item->product_name ?? $item->combo_name ?? null;
    }
}
