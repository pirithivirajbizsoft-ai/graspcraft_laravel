<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Local-disk replacement for the S3 side of AwsConfig::uploadToS3/downloadImg/
 * downloadImgBuffer. Raw photographer/kiosk captures, kiosk framed/retouched
 * order images and Web Master (frame/product/combo/object/ultra-object/ads)
 * images are written to and read from the `public` filesystem disk
 * (storage/app/public, exposed at /storage via `php artisan storage:link`)
 * instead of S3.
 *
 * AWS Rekognition itself is untouched — face indexing and face search still
 * call AWS, just from the raw bytes handed to store() rather than from an S3
 * object reference. See AwsConfig::indexFaceBytes / getMatchedImages.
 */
class LocalImageStorage
{
    private const DISK = 'public';

    /**
     * The `public` disk is configured with `throw => false`, so a failed write
     * returns false instead of throwing. Turning that into an exception here
     * keeps every caller's existing try/catch failure handling working exactly
     * as it did when a failed S3 putObject threw.
     */
    public function store(string $path, string $contents): void
    {
        if (! Storage::disk(self::DISK)->put($path, $contents)) {
            throw new \RuntimeException("Failed to write {$path} to local storage");
        }
    }

    /**
     * @throws \RuntimeException if the file doesn't exist or can't be read —
     *   same failure shape a missing S3 object used to produce, so existing
     *   try/catch call sites (e.g. the order ZIP export) behave unchanged.
     */
    public function get(string $path): string
    {
        $contents = Storage::disk(self::DISK)->get($path);

        if ($contents === null) {
            throw new \RuntimeException("File not found in local storage: {$path}");
        }

        return $contents;
    }

    public function url(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }
}
