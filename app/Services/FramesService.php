<?php

namespace App\Services;

use App\Models\Frame;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/admin/frames/frames.service.ts. */
class FramesService
{
    public function create(array $dto): Frame
    {
        return Frame::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Frame::query();

        if (! empty($req['search_text'])) {
            $query->where('frame_name', 'ILIKE', '%'.$req['search_text'].'%');
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?Frame
    {
        return Frame::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto): array
    {
        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [Frame::query()->where('id', $id)->update($dto)];
    }

    public function remove(string $id): int
    {
        return Frame::query()->where('id', $id)->delete();
    }
}
