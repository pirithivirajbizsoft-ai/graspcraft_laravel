<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One captured cart line (a Combo + quantity) belonging to an
 * OrderStockMigration — the "what was ordered" snapshot taken at order
 * creation, since `cart` itself is keyed by customer (not order) and is
 * hard-deleted once checkout completes (see CartService::deleteMultipleCart).
 * New functionality, not a port of anything in graspcraft_backend.
 *
 * combo_name is a snapshot of Combo::combo_name at capture time, so the
 * migration screen and failure messages still make sense even if the combo
 * is later renamed or removed.
 */
class OrderStockMigrationItem extends Model
{
    protected $table = 'inventory_order_stock_migration_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'migration_id',
        'combo_id',
        'combo_name',
        'quantity',
        'status',
        'shortfall_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'shortfall_quantity' => 'decimal:3',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(OrderStockMigration::class, 'migration_id');
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'combo_id');
    }

    /**
     * Per-BOM-component deduction outcome for this line — see
     * StockMigrationService::resolveBomRequirements() and
     * OrderStockMigrationBomItem.
     */
    public function bomItems(): HasMany
    {
        return $this->hasMany(OrderStockMigrationBomItem::class, 'migration_item_id');
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
