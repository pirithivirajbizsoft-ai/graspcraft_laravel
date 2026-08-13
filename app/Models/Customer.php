<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of graspcraft_backend/src/database/models/Customers.ts.
 *
 * Note that unlike Users, the columns here are NOT encrypted — customerRegistor
 * encrypts only `password`, explicitly, before create(). Adding accessors here
 * would corrupt every existing customer name and email.
 */
class Customer extends BaseModel
{
    protected $table = 'customers';

    protected $fillable = [
        'id',
        'name',
        'user_name',
        'password',
        'status',
        'email_id',
        'customer_code',
        'ph_number',
        'role_id',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function framImgMap(): HasMany
    {
        return $this->hasMany(FramImgMap::class, 'customer_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id', 'id');
    }

    public function cart(): HasMany
    {
        return $this->hasMany(Cart::class, 'customer_id', 'id');
    }

    public function imagesUploads(): HasMany
    {
        return $this->hasMany(ImagesUpload::class, 'customer_id', 'id');
    }

    public function photoCart(): HasMany
    {
        return $this->hasMany(PhotoCart::class, 'customer_id', 'id');
    }

    /**
     * The staff member this customer was attributed to.
     *
     * Joined customers.role_id -> users.role_id, not to users.id — that is what
     *
     * @BelongsTo(foreignKey: 'role_id', targetKey: 'role_id') declares, and the
     * staff report walks it as `customer.user.name`.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'role_id', 'role_id');
    }
}
