<?php

namespace App\Services\Inventory;

use App\Models\InventoryProduct;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Messages;
use Illuminate\Support\Facades\DB;

/**
 * New functionality, not a port of anything in graspcraft_backend.
 *
 * Stock Out only ever acts on the Inventory module's own Product
 * (item_type INVENTORY_PRODUCT) — the pre-existing, Node-owned Product and
 * Combo catalogs have no stock maintenance of their own.
 */
class StockOutService
{
    /**
     * reason (what the caller picks) -> movement_subtype (what the ledger
     * stores). reference_type instead stores strtoupper(reason) directly,
     * so 'other' ends up as reference_type=OTHER / movement_subtype=ADJUSTMENT.
     */
    private const REASON_SUBTYPES = [
        'sale' => 'SALE',
        'damaged' => 'DAMAGED',
        'expired' => 'EXPIRED',
        'wastage' => 'WASTAGE',
        'other' => 'ADJUSTMENT',
    ];

    public function __construct(private readonly StockService $stockService) {}

    /** @return array{movement: StockMovement, below_minimum: bool} */
    public function create(array $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $itemType = StockService::ITEM_TYPE_INVENTORY_PRODUCT;
            $itemId = $dto['item_id'];
            $warehouse = Warehouse::query()->findOrFail($dto['warehouse_id']);

            $quantity = (float) $dto['quantity'];

            $inventoryProduct = InventoryProduct::query()->lockForUpdate()->findOrFail($itemId);

            if (! $inventoryProduct->is_stockable) {
                throw new \RuntimeException(Messages::EM026);
            }

            // uom_id is optional on stock-out; defaults to the product's own unit.
            $inputUom = ! empty($dto['uom_id']) ? Uom::query()->findOrFail($dto['uom_id']) : $inventoryProduct->uom;
            $this->stockService->assertCompatible($inputUom, $inventoryProduct->uom);

            $quantity = $this->stockService->toBaseQuantity($inputUom, $quantity);
            // Cost on a stock-out is always the product's current average
            // cost, never user-entered, so COGS stays internally consistent
            // regardless of who processes the transaction.
            $unitCost = $inventoryProduct->average_cost !== null ? (float) $inventoryProduct->average_cost : 0.0;

            $available = $this->stockService->currentQuantity($itemType, $itemId, $warehouse->id);
            if ($quantity > $available) {
                $factor = $this->stockService->resolveConversionFactor($inputUom);
                $availableDisplay = $factor > 0 ? $available / $factor : $available;
                $unitLabel = ' '.$inputUom->uom_short;

                throw new \RuntimeException(
                    Messages::EM027.' Available: '
                    .rtrim(rtrim(number_format($availableDisplay, 3), '0'), '.')
                    .$unitLabel
                );
            }

            $reason = $dto['reason'];

            $movement = StockMovement::create([
                'movement_number' => $this->stockService->generateMovementNumber('OUT'),
                'movement_type' => 'OUT',
                'movement_subtype' => self::REASON_SUBTYPES[$reason] ?? 'ADJUSTMENT',
                'item_type' => $itemType,
                'item_id' => $itemId,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'input_uom_id' => $inputUom->id,
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 2),
                'reference_type' => strtoupper($reason),
                'reference_id' => $dto['reference_number'] ?? ('OUT-'.now()->format('YmdHis')),
                'notes' => $dto['notes'] ?? null,
            ]);

            $this->stockService->applyMovement($itemType, $itemId, $warehouse, 'OUT', $quantity);

            $inventoryProduct->refresh();
            $belowMinimum = $inventoryProduct->min_stock !== null
                && (float) $inventoryProduct->min_stock > 0
                && (float) $inventoryProduct->current_stock <= (float) $inventoryProduct->min_stock;

            return [
                'movement' => $movement->fresh(['item', 'warehouse', 'inputUom']),
                'below_minimum' => $belowMinimum,
            ];
        });
    }
}
