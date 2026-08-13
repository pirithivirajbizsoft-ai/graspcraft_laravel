<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of graspcraft_backend/src/database/models/Cart.ts.
 *
 * `combo_json` holds the whole basket snapshot the kiosk built:
 *
 *     { combo_id, product_list: [{ product_id, photo_limit, product_type,
 *       magnet_img_name, certificate_img_name, frame_img_ids: [] }] }
 *
 * It is the only record of what was ordered — `orders` carries no line items —
 * so several flows (email images, zip download, commission accrual) read it.
 */
class Cart extends BaseModel
{
    protected $table = 'cart';

    protected $fillable = [
        'id',
        'sub_order_id',
        'combo_id',
        'combo_json',
        'customer_id',
        'qty',
    ];

    protected $casts = [
        'combo_json' => 'array',
        'qty' => 'integer',
    ];

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'combo_id', 'id');
    }

    public function customers(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
