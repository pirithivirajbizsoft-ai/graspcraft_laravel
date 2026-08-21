<?php

namespace App\Services\Inventory;

use App\Models\InventoryProduct;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Support\Messages;
use Illuminate\Support\Collection;

/** New functionality, not a port of anything in graspcraft_backend. */
class UomService
{
    public function create(array $dto): Uom
    {
        $dto['uom_short'] = strtoupper($dto['uom_short']);
        // Only a derived unit is allowed to carry a real conversion factor.
        $dto['conversion_factor'] = empty($dto['base_unit']) ? 1 : ($dto['conversion_factor'] ?? 1);

        return Uom::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Uom::query()->with('baseUnit');

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ILIKE', "%{$s}%")->orWhere('uom_short', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?Uom
    {
        return Uom::query()->with('baseUnit')->where('id', $id)->first();
    }

    public function baseUnits(): Collection
    {
        return Uom::query()->baseUnits()->where('is_active', true)->orderBy('name')->get();
    }

    public function update(string $id, array $dto): Uom
    {
        if (isset($dto['uom_short'])) {
            $dto['uom_short'] = strtoupper($dto['uom_short']);
        }

        if (array_key_exists('base_unit', $dto)) {
            if ($dto['base_unit'] === $id) {
                throw new \RuntimeException(Messages::EM028);
            }

            if (empty($dto['base_unit'])) {
                $dto['conversion_factor'] = 1;
            }
        }

        $uom = Uom::query()->findOrFail($id);
        $uom->update($dto);

        return $uom->fresh('baseUnit');
    }

    /** Blocked if referenced by a product, a stock movement's input UOM, or a derived unit. */
    public function remove(string $id): int
    {
        $inUse = InventoryProduct::query()->where('uom_id', $id)->exists()
            || StockMovement::query()->where('input_uom_id', $id)->exists()
            || Uom::query()->where('base_unit', $id)->exists();

        if ($inUse) {
            throw new \RuntimeException(Messages::EM020);
        }

        return Uom::query()->where('id', $id)->delete();
    }
}
