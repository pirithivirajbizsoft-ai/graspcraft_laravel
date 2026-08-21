<?php

namespace App\Services\Inventory;

use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Read-only ledger listing, reused by the Stock In/Out screens for their
 * on-hand lookup instead of duplicating that query. New functionality, not
 * a port of anything in graspcraft_backend.
 */
class StockMovementService
{
    public function __construct(private readonly StockService $stockService) {}

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = StockMovement::query()->with(['item', 'warehouse', 'inputUom']);

        if (! empty($req['item_type'])) {
            $query->where('item_type', $req['item_type']);
        }

        if (! empty($req['item_id'])) {
            $query->where('item_id', $req['item_id']);
        }

        if (! empty($req['warehouse_id'])) {
            $query->where('warehouse_id', $req['warehouse_id']);
        }

        if (! empty($req['movement_type'])) {
            $query->where('movement_type', $req['movement_type']);
        }

        if (! empty($req['from_date'])) {
            $query->whereDate('created_at', '>=', $req['from_date']);
        }

        if (! empty($req['to_date'])) {
            $query->whereDate('created_at', '<=', $req['to_date']);
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?StockMovement
    {
        return StockMovement::query()->with(['item', 'warehouse', 'inputUom'])->where('id', $id)->first();
    }

    /** On-hand quantity for an item at a warehouse, for the Stock In/Out forms' live lookup. */
    public function itemInfo(string $itemType, string $itemId, string $warehouseId): array
    {
        return ['quantity' => $this->stockService->currentQuantity($itemType, $itemId, $warehouseId)];
    }

    /**
     * Current on-hand quantity per item per warehouse, across all three
     * stocked catalogs — the "Warehouse-wise Stock" report. Zero-quantity
     * rows are included (a balance row exists once any movement has ever
     * touched that item+warehouse, even if it's since netted to zero).
     *
     * @return array{count: int, rows: Collection}
     */
    public function warehouseStock(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = StockBalance::query()->with(['item', 'warehouse']);

        if (! empty($req['item_type'])) {
            $query->where('item_type', $req['item_type']);
        }

        if (! empty($req['item_id'])) {
            $query->where('item_id', $req['item_id']);
        }

        if (! empty($req['warehouse_id'])) {
            $query->where('warehouse_id', $req['warehouse_id']);
        }

        $count = (clone $query)->count();
        $rows = $query->orderBy('item_type')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }
}
