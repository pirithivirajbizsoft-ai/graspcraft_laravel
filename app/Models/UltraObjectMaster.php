<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Port of graspcraft_backend/src/database/models/UltraObjectMaster.ts. */
class UltraObjectMaster extends BaseModel
{
    protected $table = 'ultra-object-master';

    protected $fillable = [
        'id',
        'title',
        'description',
        'img_url',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $attributes = [
        'status' => true,
    ];

    public function ultraObjectObjMap(): HasMany
    {
        return $this->hasMany(UltraObjectObjMap::class, 'ultra_object_id', 'id');
    }
}
