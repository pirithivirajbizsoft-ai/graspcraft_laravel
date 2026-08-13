<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of graspcraft_backend/src/database/models/ComboUserCommission.ts.
 *
 * Per-user commission CONFIGURED on a combo product — the rate, not the money.
 * The money lives in StaffCommission, which snapshots these values at order
 * time so a later rate change cannot rewrite history.
 *
 * One combo can carry many rows (one per user), and the same user can be
 * configured on many combos with different values. Rows are replaced wholesale
 * on combo update, mirroring how ProdCombMap is handled.
 */
class ComboUserCommission extends BaseModel
{
    protected $table = 'combo-user-commission';

    protected $fillable = [
        'id',
        'combo_id',
        'user_id',
        /** 'percent' | 'amount' */
        'commission_type',
        /** percent -> 0..100, amount -> currency value */
        'commission_value',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $attributes = [
        'status' => true,
    ];

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'combo_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
