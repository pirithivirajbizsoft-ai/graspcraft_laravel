<?php

namespace App\Models;

use App\Models\Concerns\ReferencesStockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The append-only stock ledger for the Inventory module — one row per
 * Stock In / Stock Out event. Immutable by design: no updated_at, no soft
 * delete, rows are never edited or removed once written. A generic
 * reference_type/reference_id pair is kept (per the inventory reference this
 * module was built from) so other flows could log into the same ledger in
 * future without a schema change, even though only Stock In/Out write to it
 * today.
 *
 * item_type/item_id is polymorphic (see ReferencesStockItem + the morph map
 * in AppServiceProvider::boot()) so the same ledger covers three different
 * kinds of stocked item: the Inventory module's own Product, and the two
 * pre-existing, Node-owned catalog tables (Product, Combo) — neither of
 * which gained any new column of their own for this.
 */
class StockMovement extends Model
{
    use ReferencesStockItem;

    protected $table = 'inventory_stock_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Immutable ledger row: created_at only, no updated_at maintained. */
    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'movement_number',
        'movement_type',
        'movement_subtype',
        'item_type',
        'item_id',
        'warehouse_id',
        'quantity',
        'input_uom_id',
        'unit_cost',
        'total_cost',
        'batch_number',
        'expiry_date',
        'reference_type',
        'reference_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    protected $appends = ['item_label'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function inputUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'input_uom_id');
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
