<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One BOM component's deduction outcome for one captured order line
 * (OrderStockMigrationItem) — the per-component counterpart to that row's
 * own combo-balance status. New functionality, not a port of anything in
 * graspcraft_backend.
 *
 * Populated lazily inside StockMigrationService::completeOrder()/migrate(),
 * not at capture time — a Product/Combo's BOM is resolved against its
 * CURRENT definition at deduction time, same as combo composition already
 * is. status/shortfall_quantity follow the exact same PENDING -> MIGRATED
 * or FAILED lifecycle as OrderStockMigrationItem, so the same manual
 * Stock Migration retry (migrate()) can resolve a BOM shortfall exactly
 * like it resolves a combo-balance shortfall.
 */
class OrderStockMigrationBomItem extends Model
{
    protected $table = 'inventory_order_stock_migration_bom_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'migration_item_id',
        'inventory_product_id',
        'quantity',
        'status',
        'shortfall_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'shortfall_quantity' => 'decimal:3',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    public function migrationItem(): BelongsTo
    {
        return $this->belongsTo(OrderStockMigrationItem::class, 'migration_item_id');
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
