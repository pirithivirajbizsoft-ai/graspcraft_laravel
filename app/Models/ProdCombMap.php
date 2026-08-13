<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port of graspcraft_backend/src/database/models/ProdCombMap.ts. */
class ProdCombMap extends BaseModel
{
    protected $table = 'prod-comb-map';

    protected $fillable = [
        'id',
        'product_id',
        'combo_id',
    ];

    public function products(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'combo_id', 'id');
    }
}
