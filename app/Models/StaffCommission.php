<?php

namespace App\Models;

/**
 * Port of graspcraft_backend/src/database/models/StaffCommission.ts.
 *
 * Ledger of commission actually EARNED by a staff member on an order.
 *
 * Written at order-completion time and fully self-contained: the combo name and
 * price, the staff name and the configured rate are all SNAPSHOT here. Two
 * reasons:
 *
 *  1. `orders` carries no line items. The link runs orders -> customers -> cart,
 *     and cart rows are hard deleted after checkout (CartService::deleteMultipleCart),
 *     so the basket cannot be reconstructed later.
 *  2. Orders themselves are hard deleted by ReportsService::deleteOrders.
 *
 * For that second reason there are deliberately NO foreign keys to orders or
 * combo — a real constraint would either block the delete or cascade this row
 * away. Do not "fix" that by adding constraints. Same reasoning as DeletedOrder.
 */
class StaffCommission extends BaseModel
{
    protected $table = 'staff-commission';

    protected $fillable = [
        'id',

        // ─── order identity (no FK on purpose, see class doc) ────────────────
        /** orders.id (UUID) — the reliable key; order_id is not unique. */
        'order_ref_id',
        /** ORD-xxxxxxxxxxxxx — for display and search only. */
        'order_id',
        /**
         * The cart row this entry was derived from. Guards against a later order
         * for the same customer re-earning commission on cart rows that were
         * never cleared — each cart row may only ever produce commission once.
         */
        'cart_id',

        // ─── staff ───────────────────────────────────────────────────────────
        'staff_id',
        /** The raw code typed at the kiosk (users.role_id, e.g. "Fc110"). */
        'staff_code',
        /** Decrypted at write time — users.name is encrypted at rest. */
        'staff_name',

        // ─── combo snapshot ──────────────────────────────────────────────────
        'combo_id',
        'combo_name',
        'combo_price',
        'qty',

        // ─── rate snapshot + computed amount ─────────────────────────────────
        /** 'percent' | 'amount' */
        'commission_type',
        'commission_value',
        /** percent -> price * qty * value / 100, amount -> value * qty */
        'commission_amount',

        // ─── payout ──────────────────────────────────────────────────────────
        /** 'pending' | 'paid' | 'reversed'. Only a pending row may become reversed. */
        'payout_status',
        'payout_id',

        // ─── source order state ──────────────────────────────────────────────
        /** 'active' | 'cancelled' | 'deleted' — surfaced as a badge on the report. */
        'order_state',
        'order_state_changed_at',
        'order_state_changed_by',
        /** order_status at the moment the entry was written — audit trail. */
        'order_status_at_entry',
    ];

    protected $casts = [
        'qty' => 'integer',
        'order_state_changed_at' => 'datetime',
    ];

    protected $attributes = [
        'qty' => 1,
        'commission_amount' => 0,
        'payout_status' => 'pending',
        'order_state' => 'active',
    ];
}
