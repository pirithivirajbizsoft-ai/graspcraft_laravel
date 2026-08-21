<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One row per order, tracking whether its cart contents (captured at order
 * creation — see UsersService::createOrder) have been turned into real
 * stock deductions ("Stock Migration"). New functionality, not a port of
 * anything in graspcraft_backend.
 *
 * Keyed by orders.order_id (the human "ORD-…" id everything else in this
 * app already keys off), not the orders.id UUID — deliberately NOT a
 * foreign key, since `orders` is Node-owned and this table must not
 * constrain or alter it in any way.
 *
 * status lifecycle: PENDING (captured, not yet migrated) -> MIGRATED (every
 * line deducted) or FAILED (at least one line was short — the lines that
 * DID succeed are still deducted, see StockMigrationService) -> REVERSED
 * (terminal, set when the order is later cancelled/deleted).
 */
class OrderStockMigration extends Model
{
    protected $table = 'inventory_order_stock_migrations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'customer_id',
        'status',
        'warehouse_id',
        'failure_reason',
        'migrated_at',
    ];

    protected $casts = [
        'migrated_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderStockMigrationItem::class, 'migration_id');
    }

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
