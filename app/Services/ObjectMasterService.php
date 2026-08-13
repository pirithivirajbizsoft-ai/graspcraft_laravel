<?php

namespace App\Services;

use App\Models\ObjectMaster;
use App\Support\AwsConfig;
use App\Support\Enums\UploadType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/admin/object-master/object_master.service.ts. */
class ObjectMasterService
{
    /**
     * Upload the object image to the masters bucket and return its S3 key.
     *
     * The key is what ends up in img_url, and the panel renders
     * `frameBaseUrl + img_url`, so the 'objects/' prefix is part of the URL.
     */
    public function uploadImage(UploadedFile $file): string
    {
        $aws = new AwsConfig;
        $bucket = UploadType::OBJECT->bucket();

        $random8DigitString = (string) random_int(10000000, 99999999);
        $extension = $file->getClientOriginalExtension();
        $key = UploadType::OBJECT->prefix().$random8DigitString.'.'.$extension;

        $aws->uploadToS3(
            bucket: $bucket,
            key: $key,
            body: file_get_contents($file->getRealPath()),
            contentType: $file->getMimeType(),
            uploadType: UploadType::OBJECT->value,
        );

        return $key;
    }

    public function create(array $dto, ?UploadedFile $file = null): ObjectMaster
    {
        if ($file) {
            $dto['img_url'] = $this->uploadImage($file);
        }

        return ObjectMaster::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = ObjectMaster::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('title', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();

        $rows = $query->orderByDesc('created_at');

        // `limit: 0, page_no: 0` means "no pagination".
        if (($req['limit'] ?? null) !== 0 && ($req['page_no'] ?? null) !== 0) {
            $rows->offset($offset)->limit($limit);
        }

        return ['count' => $count, 'rows' => $rows->get()];
    }

    public function findOne(string $id): ?ObjectMaster
    {
        return ObjectMaster::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto, ?UploadedFile $file = null): array
    {
        if ($file) {
            $dto['img_url'] = $this->uploadImage($file);
        }

        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [ObjectMaster::query()->where('id', $id)->update(
            array_intersect_key($dto, array_flip(['title', 'description', 'img_url', 'status']))
        )];
    }

    /** Hard delete — `force: true`. */
    public function remove(string $id): int
    {
        return ObjectMaster::query()->where('id', $id)->forceDelete();
    }
}
