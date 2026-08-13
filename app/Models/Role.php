<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of graspcraft_backend/src/database/models/Role.ts.
 *
 * A role scopes which kiosks a non-super-admin user may see. Several services
 * load `Roles.findOne({ where: { user_id }, include: [RoleKioskMap] })` and map
 * the result to a list of kiosk ids used as a filter.
 */
class Role extends BaseModel
{
    protected $table = 'roles';

    protected $fillable = [
        'id',
        'role_name',
        'is_active',
        'user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function users(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function roleKioskMap(): HasMany
    {
        return $this->hasMany(RoleKioskMap::class, 'role_id', 'id');
    }
}
