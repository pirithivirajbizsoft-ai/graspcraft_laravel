<?php

namespace App\Support;

use App\Models\ImagesUpload;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Port of graspcraft_backend/src/utils/awsConfig.ts.
 *
 * Dev and prod live in separate IAM users whose policies are scoped to their own
 * buckets, so the credentials switch with NODE_ENV exactly like the bucket names
 * do. Using the dev key against the prod buckets is not a partial failure — it is
 * AccessDenied on every single upload. That selection happens once, in
 * config/photocraft.php, and both clients below read it from there.
 */
class AwsConfig
{
    private S3Client $s3;

    private RekognitionClient $rekognition;

    public function __construct()
    {
        $credentials = [
            'version' => 'latest',
            'region' => config('photocraft.aws.region'),
            'credentials' => [
                'key' => config('photocraft.aws.key'),
                'secret' => config('photocraft.aws.secret'),
            ],
        ];

        // Only for PHP builds with no CA bundle of their own (Windows); on a
        // deployed Linux host this is unset and the system trust store is used.
        if ($caBundle = config('photocraft.aws.ca_bundle')) {
            $credentials['http'] = ['verify' => $caBundle];
        }

        $this->s3 = new S3Client($credentials);
        $this->rekognition = new RekognitionClient($credentials);
    }

    /**
     * Upload one object, and — only for a raw photographer capture — index the
     * face in it.
     *
     * The indexFaces side effect is keyed off $uploadType and NOT off the bucket
     * name: several upload types now resolve to the same bucket, so a name
     * comparison would fire for the wrong type.
     *
     * ExternalImageId is set to the S3 key, which is how getMatchedImages() maps
     * a Rekognition hit back to an images-uploads row. Rekognition rejects '/'
     * in that field, which is why image uploads carry no folder prefix.
     *
     * @param  resource|string  $body
     */
    public function uploadToS3(
        string $bucket,
        string $key,
        mixed $body,
        ?string $contentType = null,
        ?string $uploadType = null,
    ): Result {
        try {
            $response = $this->s3->putObject(array_filter([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentType' => $contentType,
            ], fn ($v) => $v !== null));

            if ($uploadType === 'image') {
                $result = $this->rekognition->indexFaces([
                    'CollectionId' => config('photocraft.aws.collection_id'),
                    'Image' => [
                        'S3Object' => [
                            'Bucket' => config('photocraft.is_prod')
                                ? config('photocraft.buckets.images_prod')
                                : config('photocraft.buckets.images'),
                            'Name' => $key,
                        ],
                    ],
                    'ExternalImageId' => $key,
                ]);

                Log::info('Indexed face from image', [
                    'key' => $key,
                    'faces' => count($result['FaceRecords'] ?? []),
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('Upload failed: '.$e->getMessage(), ['key' => $key]);
            throw $e;
        }
    }

    /**
     * Search the face collection for the supplied photo and return the matching
     * images-uploads rows.
     *
     * Threshold 90 / MaxFaces 10 are the Node values. The rows come back
     * newest-first and carry only id and img_url, which is what the kiosk needs.
     *
     * @return Collection<int, ImagesUpload>
     */
    public function getMatchedImages(string $imageBytes)
    {
        $data = $this->rekognition->searchFacesByImage([
            'CollectionId' => config('photocraft.aws.collection_id'),
            'Image' => ['Bytes' => $imageBytes],
            'FaceMatchThreshold' => 90,
            'MaxFaces' => 10,
        ]);

        $keys = [];
        foreach ($data['FaceMatches'] ?? [] as $match) {
            if (isset($match['Face']['ExternalImageId'])) {
                $keys[] = $match['Face']['ExternalImageId'];
            }
        }

        return ImagesUpload::query()
            ->select(['id', 'img_url'])
            ->whereIn('img_url', $keys)
            ->orderByDesc('created_at')
            ->get();
    }

    public function createCollection(string $collectionId): array
    {
        return $this->unwrap($this->rekognition->createCollection([
            'CollectionId' => $collectionId,
        ])->toArray());
    }

    public function listCollection(): array
    {
        return $this->unwrap($this->rekognition->listCollections([])->toArray());
    }

    public function listFaces(string $collectionId): array
    {
        return $this->unwrap($this->rekognition->listFaces([
            'CollectionId' => $collectionId,
        ])->toArray());
    }

    public function deleteCollection(string $collectionId): array
    {
        return $this->unwrap($this->rekognition->deleteCollection([
            'CollectionId' => $collectionId,
        ])->toArray());
    }

    /**
     * Drop the PHP SDK's `@metadata` envelope.
     *
     * These four responses are returned to the panel verbatim. The JS SDK's
     * `.promise()` resolves to the parsed payload alone, while the PHP SDK's
     * Result carries an extra `@metadata` key holding the raw HTTP status,
     * headers and timing. Leaving it in would add a key the panel never saw and
     * would leak request ids into a client-visible payload.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function unwrap(array $result): array
    {
        unset($result['@metadata']);

        return $result;
    }

    /**
     * Presigned GET for a framed image, valid for 120 seconds, forcing a
     * download rather than an inline render.
     */
    public function downloadImg(string $img): string
    {
        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->framedBucket(),
            'Key' => $img,
            'ResponseContentDisposition' => "attachment; filename={$img}",
        ]);

        return (string) $this->s3->createPresignedRequest($command, '+120 seconds')->getUri();
    }

    /** The framed image itself, for zipping. */
    public function downloadImgBuffer(string $img): string
    {
        $response = $this->s3->getObject([
            'Bucket' => $this->framedBucket(),
            'Key' => $img,
        ]);

        return (string) $response['Body'];
    }

    private function framedBucket(): ?string
    {
        return config('photocraft.is_prod')
            ? config('photocraft.buckets.framed_images_prod')
            : config('photocraft.buckets.framed_images');
    }
}
