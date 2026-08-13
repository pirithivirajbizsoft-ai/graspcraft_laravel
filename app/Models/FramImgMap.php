<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of graspcraft_backend/src/database/models/FramImgMap.ts.
 *
 * One row per framed photo. `merged_img_name` is the S3 key the kiosk uploaded;
 * `edited_img_name` supersedes it once a photo has been retouched, which is why
 * readers throughout use `edited_img_name ?? merged_img_name`.
 */
class FramImgMap extends BaseModel
{
    protected $table = 'fram-img-map';

    protected $fillable = [
        'id',
        'customer_id',
        'org_img_id',
        'org_img_name',
        'frame_id',
        'frame_name',
        'merged_img_name',
        'edited_img_name',
        'is_edited',
    ];

    protected $casts = [
        'is_edited' => 'boolean',
    ];

    protected $attributes = [
        'is_edited' => false,
    ];

    public function customers(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function imagesUploads(): BelongsTo
    {
        return $this->belongsTo(ImagesUpload::class, 'org_img_id', 'id');
    }

    public function photoCart(): HasMany
    {
        return $this->hasMany(PhotoCart::class, 'frame_img_id', 'id');
    }

    /** The displayed image: the retouched version when there is one. */
    public function displayName(): ?string
    {
        return $this->edited_img_name ?? $this->merged_img_name;
    }
}
