<?php

namespace App\Models;

/**
 * Port of graspcraft_backend/src/database/models/ProductSize.ts.
 *
 * The columns are height/width, but findAllProductSize searches on
 * height/`weight` — `weight` does not exist. That is a live bug in the Node
 * service, not a transcription slip here; see ProductsService::findAllProductSize
 * for how it is handled.
 */
class ProductSize extends BaseModel
{
    protected $table = 'product-size';

    protected $fillable = [
        'id',
        'height',
        'width',
    ];
}
