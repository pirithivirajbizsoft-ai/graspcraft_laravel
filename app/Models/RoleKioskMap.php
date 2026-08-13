<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port of graspcraft_backend/src/database/models/RoleKioskMap.ts. */
class RoleKioskMap extends BaseModel
{
    protected $table = 'role-kiosk-map';

    protected $fillable = [
        'id',
        'role_id',
        'kiosk_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    /**
     * The kiosk is a Users row with user_type = 'kiosk'.
     *
     * The alias matters: RolesService includes the Users model here without
     * naming it, and Sequelize resolves that to this association, so the
     * serialised key is `kiosk` — which is what the panel reads.
     */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kiosk_id', 'id');
    }
}
