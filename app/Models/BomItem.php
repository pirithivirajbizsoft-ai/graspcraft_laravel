<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One BOM line: "one unit of the owner (a Product or a Combo) consumes this
 * much of this Inventory Item." New functionality, not a port of anything
 * in graspcraft_backend.
 *
 * owner_type/owner_id is polymorphic (see the morph map in
 * AppServiceProvider::boot(), which registers 'PRODUCT' => Product::class
 * and reuses the 'COMBO' => Combo::class entry the stock ledger already
 * declares). quantity is expressed directly in the referenced Inventory
 * Item's own base/stocking unit — no separate UOM field, unlike
 * inventory_stock_movements, since there is only ever one unit per BOM
 * line to reconcile rather than an input unit vs a storage unit.
 *
 * Rows are replaced wholesale on the owner's create/update (see
 * BomService::replaceFor) rather than diffed, matching how ProdCombMap and
 * ComboUserCommission are already handled for Combo.
 */
class BomItem extends Model
{
    protected $table = 'inventory_bom_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_type',
        'owner_id',
        'inventory_product_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }

    public function inventoryProduct(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
