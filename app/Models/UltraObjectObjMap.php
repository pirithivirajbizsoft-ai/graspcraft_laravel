<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port of graspcraft_backend/src/database/models/UltraObjectObjMap.ts. */
class UltraObjectObjMap extends BaseModel
{
    protected $table = 'ultra-object-obj-map';

    protected $fillable = [
        'id',
        'ultra_object_id',
        'object_id',
    ];

    public function ultraObjectMaster(): BelongsTo
    {
        return $this->belongsTo(UltraObjectMaster::class, 'ultra_object_id', 'id');
    }

    public function objectMaster(): BelongsTo
    {
        return $this->belongsTo(ObjectMaster::class, 'object_id', 'id');
    }
}
