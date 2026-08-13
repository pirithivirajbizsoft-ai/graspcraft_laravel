<?php

namespace App\Services;

use App\Models\ObjectMaster;
use App\Models\UltraObjectMaster;
use App\Models\UltraObjectObjMap;
use App\Support\AwsConfig;
use App\Support\Enums\UploadType;
use Illuminate\Http\UploadedFile;

/**
 * Port of graspcraft_backend/src/modules/admin/ultra-object-master/ultra_object_master.service.ts.
 *
 * An "ultra object" is a named group of ObjectMasters. The mapping table is read
 * back with two explicit queries rather than an eager load, because the Node
 * version does the same and the panel expects the flat `objectMasters` key that
 * produces.
 */
class UltraObjectMasterService
{
    public function uploadImage(UploadedFile $file): string
    {
        $aws = new AwsConfig;
        $bucket = UploadType::ULTRA_OBJECT->bucket();

        $random8DigitString = (string) random_int(10000000, 99999999);
        $extension = $file->getClientOriginalExtension();
        $key = UploadType::ULTRA_OBJECT->prefix().$random8DigitString.'.'.$extension;

        $aws->uploadToS3(
            bucket: $bucket,
            key: $key,
            body: file_get_contents($file->getRealPath()),
            contentType: $file->getMimeType(),
            uploadType: UploadType::ULTRA_OBJECT->value,
        );

        return $key;
    }

    public function create(array $dto, ?UploadedFile $file = null): UltraObjectMaster
    {
        $objectIds = $dto['object_ids'] ?? [];
        unset($dto['object_ids']);

        if ($file) {
            $dto['img_url'] = $this->uploadImage($file);
        }

        $ultraObject = UltraObjectMaster::create($dto);

        foreach ($objectIds as $objectId) {
            UltraObjectObjMap::create([
                'ultra_object_id' => $ultraObject->id,
                'object_id' => $objectId,
            ]);
        }

        return $ultraObject;
    }

    /** @return array{count: int, rows: array<int, array<string, mixed>>} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = UltraObjectMaster::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('title', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();

        $paged = $query->orderByDesc('created_at');

        if (($req['limit'] ?? null) !== 0 && ($req['page_no'] ?? null) !== 0) {
            $paged->offset($offset)->limit($limit);
        }

        $rows = $paged->get()->map(fn ($ultraObj) => array_merge(
            $ultraObj->toArray(),
            ['objectMasters' => $this->objectMastersFor($ultraObj->id)],
        ))->all();

        return ['count' => $count, 'rows' => $rows];
    }

    /** @return array<string, mixed>|null */
    public function findOne(string $id): ?array
    {
        $ultraObj = UltraObjectMaster::query()->where('id', $id)->first();

        if (! $ultraObj) {
            return null;
        }

        return array_merge(
            $ultraObj->toArray(),
            ['objectMasters' => $this->objectMastersFor($id)],
        );
    }

    public function update(string $id, array $dto, ?UploadedFile $file = null): array
    {
        $objectIds = $dto['object_ids'] ?? null;
        unset($dto['object_ids']);

        if ($file) {
            $dto['img_url'] = $this->uploadImage($file);
        }

        $updated = [UltraObjectMaster::query()->where('id', $id)->update(
            array_intersect_key($dto, array_flip(['title', 'description', 'img_url', 'status']))
        )];

        // Note: an EMPTY array leaves the existing mapping alone — the Node guard
        // is `object_ids && object_ids.length > 0`, not a presence check. Only a
        // non-empty list replaces the mapping.
        if (! empty($objectIds)) {
            UltraObjectObjMap::query()->where('ultra_object_id', $id)->forceDelete();

            foreach ($objectIds as $objectId) {
                UltraObjectObjMap::create([
                    'ultra_object_id' => $id,
                    'object_id' => $objectId,
                ]);
            }
        }

        return $updated;
    }

    /** Hard delete, mapping included. */
    public function remove(string $id): int
    {
        $deleted = UltraObjectMaster::query()->where('id', $id)->forceDelete();

        if ($deleted) {
            UltraObjectObjMap::query()->where('ultra_object_id', $id)->forceDelete();
        }

        return $deleted;
    }

    /** @return array<int, array<string, mixed>> */
    private function objectMastersFor(string $ultraObjectId): array
    {
        $objectIds = UltraObjectObjMap::query()
            ->where('ultra_object_id', $ultraObjectId)
            ->pluck('object_id')
            ->filter()
            ->all();

        if ($objectIds === []) {
            return [];
        }

        return ObjectMaster::query()->whereIn('id', $objectIds)->get()->toArray();
    }
}
