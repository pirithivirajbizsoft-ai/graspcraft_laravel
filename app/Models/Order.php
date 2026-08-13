<?php

namespace App\Models;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port of graspcraft_backend/src/database/models/Orders.ts. */
class Order extends BaseModel
{
    protected $table = 'orders';

    protected $fillable = [
        'id',
        'order_id',
        'kiosk_id',
        'customer_id',
        'date',
        'time',
        'amount',
        'discount',
        'print_count',
        'order_status',
        'receipt_img',
        'remarks',
        'payment_type',
        'response_json',
        /*
         * Staff attribution for commission. Optional — an order completes with or
         * without it.
         *
         * `staff_code` is the raw value typed at the kiosk (users.role_id, e.g.
         * "Fc110"); `staff_id` is the users.id it resolved to. Both are kept: the
         * code is not guaranteed unique, so when it resolves to zero or many users
         * the code is stored plus a note and staff_id is left null.
         */
        'staff_id',
        'staff_code',
        'staff_resolve_note',
    ];

    /*
     * JsonObject rather than 'array': an empty JSON object decoded into a PHP
     * array is indistinguishable from an empty list, so a request carrying
     * `response_json: {}` would be stored — and returned — as `[]`.
     */
    protected $casts = [
        'response_json' => JsonObject::class,
    ];

    /**
     * Aliased to `user` in the Node model, and the alias matters: several
     * queries filter on '$user.id$' and the panel reads `order.user.name`.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kiosk_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
