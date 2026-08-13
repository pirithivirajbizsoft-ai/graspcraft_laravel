<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Port of graspcraft_backend/src/database/models/Products.ts. */
class Product extends BaseModel
{
    protected $table = 'products';

    protected $fillable = [
        'id',
        'product_name',
        'price',
        'size',
        'description',
        'photo_limit',
        'product_type',
        'img_url',
        'status',
        'certificate_name',
    ];

    protected $casts = [
        'price' => 'integer',
        'photo_limit' => 'integer',
        'status' => 'boolean',
    ];

    /** @Default(ProductType.OTHERS) — also the column default in schema.sql. */
    protected $attributes = [
        'product_type' => 'others',
    ];

    public function prodCombMap(): HasMany
    {
        return $this->hasMany(ProdCombMap::class, 'product_id', 'id');
    }
}
