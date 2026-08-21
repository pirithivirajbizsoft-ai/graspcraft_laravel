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
 * A transfer moves stock for the SAME item between two warehouses. There is
 * no dedicated table — it's two linked rows in the existing
 * inventory_stock_movements ledger (one OUT at the source, one IN at the
 * destination) sharing one reference_id (a TRF-YYYYMMDD-#### number,
 * reference_type='TRANSFER'), always identical quantity/cost on both legs
 * (cost-neutral — priced at the item's current average cost, never
 * recalculated by a transfer). Unlike the inventory reference this module
 * was built from, this DOES support UOM conversion for an Inventory
 * Product, for consistency with Stock In/Out, which already have it.
 */
class StockTransferService
{
    public function __construct(private readonly StockService $stockService) {}

    /** @return array{out: StockMovement, in: StockMovement} */
    public function create(array $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $itemType = $dto['item_type'];
            $itemId = $dto['item_id'];
            $fromWarehouseId = $dto['from_warehouse_id'];
            $toWarehouseId = $dto['to_warehouse_id'];

            if ($fromWarehouseId === $toWarehouseId) {
                throw new \RuntimeException(Messages::EM031);
            }

            $fromWarehouse = Warehouse::query()->findOrFail($fromWarehouseId);
            $toWarehouse = Warehouse::query()->findOrFail($toWarehouseId);

            $quantity = (float) $dto['quantity'];
            $inputUom = null;
            $unitCost = 0.0;

            if ($itemType === StockService::ITEM_TYPE_INVENTORY_PRODUCT) {
                $inventoryProduct = InventoryProduct::query()->lockForUpdate()->findOrFail($itemId);

                if (! $inventoryProduct->is_stockable) {
                    throw new \RuntimeException(Messages::EM026);
                }

                $inputUom = ! empty($dto['uom_id']) ? Uom::query()->findOrFail($dto['uom_id']) : $inventoryProduct->uom;
                $this->stockService->assertCompatible($inputUom, $inventoryProduct->uom);

                $quantity = $this->stockService->toBaseQuantity($inputUom, $quantity);
                $unitCost = $inventoryProduct->average_cost !== null ? (float) $inventoryProduct->average_cost : 0.0;
            } elseif ($itemType === StockService::ITEM_TYPE_PRODUCT) {
                Product::query()->findOrFail($itemId);
            } elseif ($itemType === StockService::ITEM_TYPE_COMBO) {
                Combo::query()->findOrFail($itemId);
            }

            // Feasibility is checked only against the source warehouse — receiving
            // stock at the destination can never fail.
            $available = $this->stockService->currentQuantity($itemType, $itemId, $fromWarehouseId);
            if ($quantity > $available) {
                if ($inputUom) {
                    $factor = $this->stockService->resolveConversionFactor($inputUom);
                    $availableDisplay = $factor > 0 ? $available / $factor : $available;
                    $unitLabel = ' '.$inputUom->uom_short;
                } else {
                    $availableDisplay = $available;
                    $unitLabel = '';
                }

                throw new \RuntimeException(
                    Messages::EM027.' at source warehouse. Available: '
                    .rtrim(rtrim(number_format($availableDisplay, 3), '0'), '.')
                    .$unitLabel
                );
            }

            $transferNumber = $this->stockService->generateTransferNumber();
            $reason = $dto['transfer_reason'];
            $userNotes = trim($dto['notes'] ?? '');
            $totalCost = round($quantity * $unitCost, 2);

            $common = [
                'item_type' => $itemType,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'input_uom_id' => $inputUom?->id,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'batch_number' => $dto['batch_number'] ?? null,
                'expiry_date' => $dto['expiry_date'] ?? null,
                'reference_type' => 'TRANSFER',
                'reference_id' => $transferNumber,
            ];

            $out = StockMovement::create([
                ...$common,
                'movement_number' => $transferNumber.'-OUT',
                'movement_type' => 'OUT',
                'movement_subtype' => 'TRANSFER_OUT',
                'warehouse_id' => $fromWarehouseId,
                'notes' => "Transfer to {$toWarehouse->name}. Reason: {$reason}.".($userNotes !== '' ? ' '.$userNotes : ''),
            ]);

            $in = StockMovement::create([
                ...$common,
                'movement_number' => $transferNumber.'-IN',
                'movement_type' => 'IN',
                'movement_subtype' => 'TRANSFER_IN',
                'warehouse_id' => $toWarehouseId,
                'notes' => "Transfer from {$fromWarehouse->name}. Reason: {$reason}.".($userNotes !== '' ? ' '.$userNotes : ''),
            ]);

            // Two calls against the same item: OUT then IN nets out to no change
            // in the item's total stock, only its per-warehouse split — for an
            // Inventory Product, current_stock is recomputed as SUM(balances)
            // inside each call, so it ends up correct without any extra step.
            $this->stockService->applyMovement($itemType, $itemId, $fromWarehouse, 'OUT', $quantity);
            $this->stockService->applyMovement($itemType, $itemId, $toWarehouse, 'IN', $quantity);

            return [
                'out' => $out->fresh(['item', 'warehouse', 'inputUom']),
                'in' => $in->fresh(['item', 'warehouse', 'inputUom']),
            ];
        });
    }
}
