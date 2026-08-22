<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Port of graspcraft_backend/src/database/models/Combo.ts. */
class Combo extends BaseModel
{
    protected $table = 'combo';

    protected $fillable = [
        'id',
        'combo_name',
        'price',
        'size',
        'description',
        'img_url',
        'status',
    ];

    protected $casts = [
        'price' => 'integer',
        'status' => 'boolean',
    ];

    public function prodCombMap(): HasMany
    {
        return $this->hasMany(ProdCombMap::class, 'combo_id', 'id');
    }

    public function cart(): HasMany
    {
        return $this->hasMany(Cart::class, 'combo_id', 'id');
    }

    public function comboUserCommission(): HasMany
    {
        return $this->hasMany(ComboUserCommission::class, 'combo_id', 'id');
    }

    /**
     * BOM components consumed when one unit of this Combo is sold, in
     * addition to whatever its constituent Products' own BOM requires —
     * see StockMigrationService::resolveBomRequirements(). New
     * functionality, not a port of anything in graspcraft_backend. See
     * App\Models\BomItem.
     */
    public function bomItems(): MorphMany
    {
        return $this->morphMany(BomItem::class, 'owner', 'owner_type', 'owner_id');
    }
}
