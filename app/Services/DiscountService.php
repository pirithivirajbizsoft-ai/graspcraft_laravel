<?php

namespace App\Services;

use App\Models\Discount;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/discount/discount.service.ts. */
class DiscountService
{
    public function create(array $dto): Discount
    {
        return Discount::create($dto);
    }

    public function findOne(string $id): ?Discount
    {
        return Discount::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto): array
    {
        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [Discount::query()->where('id', $id)->update($dto)];
    }

    public function remove(string $id): int
    {
        return Discount::query()->where('id', $id)->delete();
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Discount::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('discount_code', 'ILIKE', "%{$s}%")
                    /*
                     * DELIBERATE, FRAMEWORK-DRIVEN DEVIATION — this endpoint is
                     * broken in the Node app and works here.
                     *
                     * discount_amount is an integer column. Sequelize emits a bare
                     * `discount_amount ILIKE $1`, which Postgres rejects with
                     * `operator does not exist: integer ~~* unknown`, so ANY search
                     * on the discount list currently returns the ER001/EM008
                     * envelope instead of results.
                     *
                     * Laravel's Postgres grammar casts LIKE/ILIKE operands to text
                     * automatically (`"discount_amount"::text ILIKE ?`), so the
                     * same query simply succeeds. Reproducing the failure would
                     * mean dropping to whereRaw purely to make the query throw,
                     * which is not worth doing.
                     *
                     * Recorded in README "Known deviations". The same applies to
                     * PcAmtMasterService::findAll (photo_count, price).
                     */
                    ->orWhere('discount_amount', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    /**
     * Looked up by CODE, and selects ONLY discount_amount — the kiosk reads
     * `data.discount_amount` and the row carries nothing else.
     */
    public function findAmount(string $code): ?Discount
    {
        return Discount::query()
            ->select(['discount_amount'])
            ->where('discount_code', $code)
            ->first();
    }
}
