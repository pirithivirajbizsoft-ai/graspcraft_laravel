<?php

namespace App\Models;

/** Port of graspcraft_backend/src/database/models/Discount.ts. */
class Discount extends BaseModel
{
    protected $table = 'discount';

    protected $fillable = [
        'id',
        'discount_code',
        'discount_amount',
        'description',
    ];

    protected $casts = [
        'discount_amount' => 'integer',
    ];
}
