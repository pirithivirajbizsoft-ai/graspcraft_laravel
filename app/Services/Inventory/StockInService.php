<?php

namespace App\Services\Inventory;

use App\Models\Combo;
use App\Models\InventoryProduct;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Messages;
use Illuminate\Support\Facades\DB;

/**
 * New functionality, not a port of anything in graspcraft_backend.
 *
 * item_type selects which of the three stocked catalogs item_id belongs to
 * (see StockService::ITEM_TYPES). Only INVENTORY_PRODUCT carries a unit of
 * measure / conversion / weighted-average-cost — PRODUCT and COMBO (the
 * pre-existing, Node-owned catalogs) are tracked as plain counts.
 */
class StockInService
{
    public function __construct(private readonly StockService $stockService) {}

    public function create(array $dto): StockMovement
    {
        return DB::transaction(function () use ($dto) {
            $itemType = $dto['item_type'];
            $itemId = $dto['item_id'];
            $warehouse = Warehouse::query()->findOrFail($dto['warehouse_id']);

            $quantity = (float) $dto['quantity'];
            $inputUomId = null;
            $unitCost = isset($dto['unit_cost']) ? (float) $dto['unit_cost'] : null;
            $inventoryProduct = null;

            if ($itemType === StockService::ITEM_TYPE_INVENTORY_PRODUCT) {
                // Locked for the duration of the transaction so a concurrent
                // stock-in/out on the same product can't race the balance and
                // average-cost recompute below.
                $inventoryProduct = InventoryProduct::query()->lockForUpdate()->findOrFail($itemId);

                if (! $inventoryProduct->is_stockable) {
                    throw new \RuntimeException(Messages::EM026);
                }

                if (empty($dto['uom_id'])) {
                    throw new \RuntimeException(Messages::EM030);
                }

                $inputUom = Uom::query()->findOrFail($dto['uom_id']);
                $this->stockService->assertCompatible($inputUom, $inventoryProduct->uom);

                $quantity = $this->stockService->toBaseQuantity($inputUom, $quantity);
                $unitCost = $this->stockService->toBaseUnitCost($inputUom, $unitCost);
                $inputUomId = $inputUom->id;
            } elseif ($itemType === StockService::ITEM_TYPE_PRODUCT) {
                Product::query()->findOrFail($itemId);
            } elseif ($itemType === StockService::ITEM_TYPE_COMBO) {
                Combo::query()->findOrFail($itemId);
            }

            if (($dto['movement_subtype'] ?? null) === 'OPENING_STOCK') {
                $alreadyHasOpeningStock = StockMovement::query()
                    ->where('item_type', $itemType)
                    ->where('item_id', $itemId)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('movement_subtype', 'OPENING_STOCK')
                    ->exists();

                if ($alreadyHasOpeningStock) {
                    throw new \RuntimeException(Messages::EM025);
                }
            }

            $movement = StockMovement::create([
                'movement_number' => $this->stockService->generateMovementNumber('IN'),
                'movement_type' => 'IN',
                'movement_subtype' => $dto['movement_subtype'],
                'item_type' => $itemType,
                'item_id' => $itemId,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'input_uom_id' => $inputUomId,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost !== null ? round($quantity * $unitCost, 2) : null,
                'batch_number' => $dto['batch_number'] ?? null,
                'expiry_date' => $dto['expiry_date'] ?? null,
                'reference_type' => 'DIRECT',
                'reference_id' => $dto['reference_number'] ?? null,
                'notes' => $dto['notes'] ?? null,
            ]);

            $this->stockService->applyMovement($itemType, $itemId, $warehouse, 'IN', $quantity);

            if ($inventoryProduct && $unitCost !== null && $unitCost > 0) {
                // applyMovement() persisted the new current_stock through its own
                // freshly-fetched copy of the model, not this one — reload before
                // updateAverageCost() reads current_stock, or it sees a stale
                // pre-movement value and mis-derives "stock before this receipt".
                $inventoryProduct->refresh();
                $this->stockService->updateAverageCost($inventoryProduct, $quantity, $unitCost);
            }

            return $movement->fresh(['item', 'warehouse', 'inputUom']);
        });
    }
}
