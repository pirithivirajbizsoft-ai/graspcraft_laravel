<?php

namespace App\Models;

use App\Support\Crypto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of graspcraft_backend/src/database/models/Users.ts.
 *
 * Note this replaces Laravel's scaffolded Authenticatable User entirely. There
 * is no Laravel auth guard in play — sessions are stateless JWTs issued by
 * App\Support\Jwt, matching the Node app.
 *
 * Two things about this table are unusual and both are load bearing:
 *
 * 1. COMPOSITE PRIMARY KEY (id, role_id). Eloquent cannot express that, so the
 *    key here is `id` alone — which is what every query in the codebase actually
 *    uses. The Node code notes the same limitation ("Users has a composite
 *    primary key (id + role_id), so findByPk is not usable") and looks users up
 *    with where clauses throughout.
 *
 * 2. name / email_id / ph_number / password are ENCRYPTED AT REST with the
 *    deterministic cipher in App\Support\Crypto. Sequelize hides this behind
 *    column getters/setters so the services read and write plaintext; the
 *    accessors below do the same, so a service can keep saying
 *    `$user->name = 'Bob'` and get ciphertext in the column.
 *
 *    The read side is forgiving (tryDecrypt falls back to the raw value) because
 *    rows written before encryption was introduced are still plaintext.
 */
class User extends BaseModel
{
    protected $table = 'users';

    protected $fillable = [
        'id',
        'name',
        'email_id',
        'ph_number',
        'location',
        'description',
        'user_name',
        'password',
        'img',
        'del_acc',
        'user_type',
        'role_id',
        'status',
        'user_id',
    ];

    protected $casts = [
        'del_acc' => 'boolean',
        'status' => 'boolean',
    ];

    // ─── encrypted attributes ────────────────────────────────────────────────

    protected function name(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function emailId(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function phNumber(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function password(): Attribute
    {
        return $this->encryptedAttribute();
    }

    /**
     * The get/set pair Sequelize declares on each encrypted column: decrypt on
     * read (falling back to the raw value), encrypt on write, and pass
     * null/empty straight through untouched.
     */
    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypto::tryDecrypt($value) : $value,
            set: fn (?string $value) => $value ? Crypto::encrypt($value) : $value,
        );
    }

    /**
     * The ciphertext as stored, bypassing the accessor.
     *
     * userLogin() looks a user up by encrypted equality and the reports compare
     * stored values directly, so the raw form has to stay reachable.
     */
    public function rawAttribute(string $key): ?string
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Display name for a user, with the same fallback the Node helpers use.
     *
     * `readUserName()` appears in both commission services with the note that
     * admin accounts often have no `name`, only the login `user_name`.
     */
    public function displayName(): ?string
    {
        return $this->name ?: ($this->user_name ?: null);
    }

    // ─── relations ───────────────────────────────────────────────────────────

    public function imagesUploads(): HasMany
    {
        return $this->hasMany(ImagesUpload::class, 'user_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'kiosk_id', 'id');
    }

    /** Customers join on role_id, NOT on id — @HasMany(sourceKey: 'role_id'). */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'role_id', 'role_id');
    }

    public function roleKioskMap(): HasMany
    {
        return $this->hasMany(RoleKioskMap::class, 'kiosk_id', 'id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'id', 'user_id');
    }
}
