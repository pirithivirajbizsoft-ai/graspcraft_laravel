<?php

namespace App\Support\Enums;

/** Port of the UploadType enum in graspcraft_backend/src/utils/index.ts. */
enum UploadType: string
{
    case IMAGE = 'image';
    case FRAME = 'frame';
    case PRODUCT = 'product';
    case COMBO = 'combo';
    case OBJECT = 'object';
    case ULTRA_OBJECT = 'ultraObject';
    case FRAMED_IMAGES = 'framedImages';
    case ADS = 'ads';

    /**
     * @deprecated Product and combo used to share this one type, which is why
     * they landed in the same folder. Kept so the old route keeps working.
     */
    case PRODUCTCOMBO = 'productcombo';

    /**
     * Folder prefix written into the S3 key, so the masters bucket is browsable
     * instead of a flat pile of 8-digit filenames.
     *
     * The prefix is part of the key AND part of what gets stored in img_url, so
     * `cloudFrontBaseUrl + img_url` keeps working untouched on the frontend.
     *
     * Two types are deliberately left at the bucket root:
     *   IMAGE          Rekognition uses the S3 key as ExternalImageId, and that
     *                  field rejects '/'. A prefix here breaks face indexing.
     *   FRAMED_IMAGES  the key is supplied by the client (merged_img_name), and
     *                  that bucket holds only one kind of object anyway.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::IMAGE => '',
            self::FRAMED_IMAGES => '',
            self::FRAME => 'frames/',
            self::PRODUCT => 'products/',
            self::COMBO => 'combos/',
            self::OBJECT => 'objects/',
            self::ULTRA_OBJECT => 'ultra-objects/',
            self::ADS => 'users/',
            self::PRODUCTCOMBO => 'products/',
        };
    }

    /**
     * Top-level local storage folder for this type, now that image uploads are
     * written to Laravel's local disk instead of S3.
     *
     * IMAGE has no static folder: the same upload endpoint is used for both a
     * photographer's App capture and a Kiosk capture, so PguserService resolves
     * 'app' vs 'kiosk' per request from the uploading user's user_type and
     * ignores this method for that one case.
     *
     * FRAMED_IMAGES (kiosk composite/order images and retouched images) nests
     * under kiosk/, since both are produced by the kiosk-tied endpoints.
     */
    public function localFolder(): ?string
    {
        return match ($this) {
            self::IMAGE => null,
            self::FRAMED_IMAGES => 'kiosk/framed',
            self::FRAME,
            self::PRODUCT,
            self::COMBO,
            self::PRODUCTCOMBO,
            self::OBJECT,
            self::ULTRA_OBJECT,
            self::ADS => 'web-master',
        };
    }

    /**
     * Bucket for this upload type.
     *
     * No longer called by any upload flow — every image upload now writes to
     * local storage via LocalImageStorage (see localFolder() above). Left in
     * place, unused, in case S3 storage is ever reinstated.
     */
    public function bucket(): ?string
    {
        $isProd = config('photocraft.is_prod');
        $suffix = $isProd ? '_prod' : '';

        $mastersBucket = config('photocraft.buckets.productcombo'.$suffix);

        return match ($this) {
            self::IMAGE => config('photocraft.buckets.images'.$suffix),
            self::FRAME => config('photocraft.buckets.frame'.$suffix),
            self::ADS => config('photocraft.buckets.ads'.$suffix),
            self::FRAMED_IMAGES => config('photocraft.buckets.framed_images'.$suffix),
            self::PRODUCT,
            self::COMBO,
            self::OBJECT,
            self::ULTRA_OBJECT,
            self::PRODUCTCOMBO => $mastersBucket,
        };
    }
}
