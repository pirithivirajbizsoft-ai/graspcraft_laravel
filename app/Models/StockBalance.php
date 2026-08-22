<?php

namespace App\Models;

use App\Models\Concerns\ReferencesStockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Real-time per-item-per-warehouse stock total for the Inventory module.
 *
 * Internal bookkeeping table, not a screen of its own (the "Warehouse-wise
 * Stock" report reads it, but nothing writes to it directly) — every Stock
 * In/Out write updates it inside the same transaction as the ledger insert.
 * InventoryProduct.current_stock is additionally kept as SUM(current_quantity)
 * across warehouses for an Inventory Product. A Combo balance also exists
 * here (see item_type on StockMovement), fed only by the order-driven stock
 * deduction in StockMigrationService — Combo has no cached stock column of
 * its own. Not soft-deleted and has no separate audit trail; the ledger
 * (StockMovement) is the audit trail.
 */
class StockBalance extends Model
{
    use ReferencesStockItem;

    protected $table = 'inventory_stock_balances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'item_type',
        'item_id',
        'warehouse_id',
        'current_quantity',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:3',
    ];

    protected $attributes = [
        'current_quantity' => 0,
    ];

    protected $appends = ['item_label'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
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
