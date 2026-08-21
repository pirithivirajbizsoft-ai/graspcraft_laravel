<?php

namespace App\Services\Inventory;

use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Messages;
use Illuminate\Support\Collection;

/** New functionality, not a port of anything in graspcraft_backend. */
class WarehouseService
{
    public function create(array $dto): Warehouse
    {
        $this->assertNameAvailable($dto['name']);

        $dto['code'] = $this->generateCode();

        return Warehouse::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Warehouse::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ILIKE', "%{$s}%")->orWhere('code', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?Warehouse
    {
        return Warehouse::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto): Warehouse
    {
        if (! empty($dto['name'])) {
            $this->assertNameAvailable($dto['name'], $id);
        }

        $warehouse = Warehouse::query()->findOrFail($id);
        $warehouse->update($dto);

        return $warehouse->fresh();
    }

    /**
     * Deliberately stricter than the inventory reference this module was
     * built from (which allows deleting a warehouse that still has stock/
     * movements against it) — blocked here to protect the ledger's
     * referential integrity.
     */
    public function remove(string $id): int
    {
        if (StockMovement::query()->where('warehouse_id', $id)->exists()) {
            throw new \RuntimeException(Messages::EM022);
        }

        return Warehouse::query()->where('id', $id)->delete();
    }

    private function assertNameAvailable(string $name, ?string $exceptId = null): void
    {
        $exists = Warehouse::query()
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw new \RuntimeException(Messages::EM021);
        }
    }

    /** WA{year}{4-digit sequence}; the sequence resets every calendar year. */
    private function generateCode(): string
    {
        $prefix = 'WA'.now()->format('Y');

        $last = Warehouse::query()
            ->withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
