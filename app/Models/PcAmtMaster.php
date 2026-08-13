<?php

namespace App\Models;

/** Port of graspcraft_backend/src/database/models/PcAmtMaster.ts. */
class PcAmtMaster extends BaseModel
{
    protected $table = 'pc-amt-master';

    protected $fillable = [
        'id',
        'photo_count',
        'price',
    ];

    protected $casts = [
        'photo_count' => 'integer',
        'price' => 'float',
    ];
}
