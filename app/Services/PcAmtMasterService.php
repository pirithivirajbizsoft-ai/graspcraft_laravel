<?php

namespace App\Services;

use App\Models\PcAmtMaster;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/admin/pc_amt_master/pc_amt_master.service.ts. */
class PcAmtMasterService
{
    public function create(array $dto): PcAmtMaster
    {
        return PcAmtMaster::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = PcAmtMaster::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            /*
             * photo_count is integer and price is double precision. Sequelize emits
             * a bare ILIKE against both and Postgres rejects it, so search on this
             * list is broken in the Node app. Laravel's grammar casts LIKE operands
             * to text automatically, so the same query works here — a deliberate,
             * framework-driven deviation, recorded in README "Known deviations".
             */
            $query->where(function ($q) use ($s) {
                $q->where('photo_count', 'ILIKE', "%{$s}%")
                    ->orWhere('price', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?PcAmtMaster
    {
        return PcAmtMaster::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto): array
    {
        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [PcAmtMaster::query()->where('id', $id)->update($dto)];
    }

    /** Hard delete — `force: true`, unlike the other masters. */
    public function remove(string $id): int
    {
        return PcAmtMaster::query()->where('id', $id)->forceDelete();
    }
}
