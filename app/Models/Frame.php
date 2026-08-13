<?php

namespace App\Models;

/** Port of graspcraft_backend/src/database/models/Frames.ts. */
class Frame extends BaseModel
{
    protected $table = 'frames';

    protected $fillable = [
        'id',
        'frame_name',
        'img_url',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
