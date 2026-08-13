<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of graspcraft_backend/src/database/models/PhotoCart.ts.
 *
 * The photocart MODULE is commented out in the Node app and is not ported, but
 * the model itself is still registered and the table still exists, so it is kept
 * here for completeness and for the Customer::photoCart relation.
 */
class PhotoCart extends BaseModel
{
    protected $table = 'photo-cart';

    protected $fillable = [
        'id',
        'sub_order_id',
        'frame_img_id',
        'customer_id',
    ];

    public function framImgMap(): BelongsTo
    {
        return $this->belongsTo(FramImgMap::class, 'frame_img_id', 'id');
    }

    public function customers(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
