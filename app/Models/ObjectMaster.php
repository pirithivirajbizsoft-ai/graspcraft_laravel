<?php

namespace App\Models;

/** Port of graspcraft_backend/src/database/models/ObjectMaster.ts. */
class ObjectMaster extends BaseModel
{
    protected $table = 'object-master';

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
}
