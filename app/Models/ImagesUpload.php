<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of graspcraft_backend/src/database/models/ImagesUploads.ts.
 *
 * One row per RAW photographer capture. `img_url` is the bare S3 key, which is
 * also what Rekognition stores as ExternalImageId — that is why image uploads
 * get no folder prefix (see UploadType::prefix()).
 */
class ImagesUpload extends BaseModel
{
    protected $table = 'images-uploads';

    protected $fillable = [
        'id',
        'user_id',
        'customer_id',
        'date',
        'time',
        'img_url',
    ];

    public function users(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function customers(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function framImgMap(): HasMany
    {
        return $this->hasMany(FramImgMap::class, 'org_img_id', 'id');
    }
}
