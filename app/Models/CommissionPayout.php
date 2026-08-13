<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of graspcraft_backend/src/database/models/CommissionPayout.ts.
 *
 * One payout event: several pending staff-commission rows settled together.
 *
 * A payout always belongs to exactly one staff member — the API rejects a batch
 * that mixes staff — so `staff_id` here is unambiguous.
 */
class CommissionPayout extends BaseModel
{
    protected $table = 'commission-payout';

    protected $fillable = [
        'id',
        'staff_id',
        /** Snapshot — staff may be renamed or removed later. */
        'staff_name',
        'total_amount',
        /** How many ledger rows this payout settled. */
        'entry_count',
        'paid_by',
        'paid_by_name',
        /** When the system recorded the payout. */
        'paid_at',
        /**
         * The date the money actually moved, as entered by the admin. Can differ
         * from paid_at when a payout is recorded after the fact.
         */
        'payout_date',
        /** 'cash' | 'online' | 'cheque' */
        'payment_mode',
        /** Who handed over the money. Optional. */
        'released_by',
        /** Cheque number or bank transaction reference. Optional, online/cheque only. */
        'reference_no',
        'reference_date',
        'remarks',
    ];

    protected $casts = [
        'entry_count' => 'integer',
        'paid_at' => 'datetime',
        'payout_date' => 'date:Y-m-d',
        'reference_date' => 'date:Y-m-d',
    ];

    protected $attributes = [
        'total_amount' => 0,
        'entry_count' => 0,
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id', 'id');
    }
}
