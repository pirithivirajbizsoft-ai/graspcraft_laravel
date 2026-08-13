<?php

namespace App\Models;

use App\Casts\JsonObject;

/**
 * Port of graspcraft_backend/src/database/models/DeletedOrders.ts.
 *
 * Archive of orders removed through the Cash Orders List delete action. The
 * `orders` row is hard deleted, so a full copy is written here first —
 * every Orders column flattened, plus `snapshot` holding the lossless original.
 *
 * Deliberately no foreign keys to users/customers: the archive must survive
 * those parents being deleted later.
 */
class DeletedOrder extends BaseModel
{
    protected $table = 'deleted_orders';

    protected $fillable = [
        'id',

        // identity of the archived row
        /** original orders.id */
        'order_pk_id',
        /** ORD-xxxxx code */
        'order_id',

        // every Orders column, flattened
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

        /** denormalised display field, stored DECRYPTED */
        'kiosk_name',

        // original row timestamps
        'order_created_at',
        'order_updated_at',
        'order_deleted_at',

        // audit
        'deleted_by',
        'deleted_by_name',
        'deleted_on',

        /** lossless copy of the original row */
        'snapshot',
    ];

    protected $casts = [
        // JsonObject preserves an empty `{}`, which an array cast would flatten
        // to `[]` on the way back out. See the note on Order::$casts.
        'response_json' => JsonObject::class,
        'snapshot' => JsonObject::class,
        'order_created_at' => 'datetime',
        'order_updated_at' => 'datetime',
        'order_deleted_at' => 'datetime',
        'deleted_on' => 'datetime',
    ];
}
