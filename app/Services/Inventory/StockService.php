<?php

namespace App\Services\Inventory;

use App\Models\InventoryProduct;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Messages;

/**
 * Shared stock-ledger mechanics for Stock In / Stock Out: UOM conversion,
 * the real-time balance table, weighted-average costing, and movement
 * numbering. New functionality, not a port of anything in
 * graspcraft_backend — there is no database trigger to lean on here, so the
 * balance/product-stock update happens explicitly in PHP, inside the same
 * transaction as the ledger insert, exactly once per movement.
 *
 * Stock In / Stock Out / Stock Transfer only ever act on the Inventory
 * module's own Product (item_type INVENTORY_PRODUCT) — the pre-existing,
 * Node-owned Product/Combo catalogs have no stock maintenance of their own.
 * The ledger (see the morph map in AppServiceProvider::boot()) still also
 * accepts item_type COMBO, but only from the order-driven stock deduction in
 * StockMigrationService, never from a user-facing item-type choice.
 */
class StockService
{
    public const ITEM_TYPE_INVENTORY_PRODUCT = 'INVENTORY_PRODUCT';

    /** Used only by StockMigrationService's order-driven deduction, never a user-facing choice. */
    public const ITEM_TYPE_COMBO = 'COMBO';

    /** 1.0 for a base unit; the unit's own factor for a derived unit. */
    public function resolveConversionFactor(Uom $uom): float
    {
        return $uom->base_unit ? (float) $uom->conversion_factor : 1.0;
    }

    /**
     * Two units are compatible only if they share the same base unit (or one
     * IS the other's base unit). Throws if the input unit was entered in a
     * family the product's own unit doesn't belong to. Inventory-Product-only
     * — Product and Combo have no unit of measure at all.
     */
    public function assertCompatible(Uom $inputUom, Uom $productUom): void
    {
        if ($inputUom->id === $productUom->id) {
            return;
        }

        if ($inputUom->resolveBaseUnitId() !== $productUom->resolveBaseUnitId()) {
            throw new \RuntimeException(Messages::EM024);
        }
    }

    /** Quantity is always persisted in the product's base UOM units. */
    public function toBaseQuantity(Uom $inputUom, float $quantity): float
    {
        return round($quantity * $this->resolveConversionFactor($inputUom), 3);
    }

    /**
     * Normalises a cost entered per input-unit to a cost per base unit, so
     * total_cost = baseQuantity * baseUnitCost stays internally consistent
     * regardless of which unit in the family the user priced it in.
     */
    public function toBaseUnitCost(Uom $inputUom, ?float $unitCost): ?float
    {
        if ($unitCost === null) {
            return null;
        }

        $factor = $this->resolveConversionFactor($inputUom);

        return round($unitCost / ($factor > 0 ? $factor : 1), 4);
    }

    /** STK-IN-20260821-0001 / STK-OUT-20260821-0001, one sequence per type per day. */
    public function generateMovementNumber(string $type): string
    {
        $prefix = $type === 'IN' ? 'STK-IN' : 'STK-OUT';
        $pattern = $prefix.'-'.now()->format('Ymd').'-';

        $last = StockMovement::query()
            ->where('movement_number', 'like', $pattern.'%')
            ->lockForUpdate()
            ->orderByDesc('movement_number')
            ->value('movement_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $pattern.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * TRF-20260821-0001, one sequence per day shared by every transfer
     * (not per warehouse-pair) — scans reference_id (not movement_number),
     * since a transfer's two rows use {this}-OUT/{this}-IN as their
     * movement_number instead.
     */
    public function generateTransferNumber(): string
    {
        $pattern = 'TRF-'.now()->format('Ymd').'-';

        $last = StockMovement::query()
            ->where('reference_type', 'TRANSFER')
            ->where('reference_id', 'like', $pattern.'%')
            ->lockForUpdate()
            ->orderByDesc('reference_id')
            ->value('reference_id');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $pattern.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Upserts inventory_stock_balances for (item, warehouse). For an
     * Inventory Product this also recomputes InventoryProduct.current_stock
     * as the sum across every warehouse; Product and Combo have no such
     * column, so inventory_stock_balances stays their only source of truth.
     * Call within the same DB transaction as the StockMovement insert.
     * Throws if an OUT movement would take the balance negative.
     */
    public function applyMovement(string $itemType, string $itemId, Warehouse $warehouse, string $movementType, float $quantity): StockBalance
    {
        $balance = StockBalance::query()
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $balance = new StockBalance([
                'item_type' => $itemType,
                'item_id' => $itemId,
                'warehouse_id' => $warehouse->id,
                'current_quantity' => 0,
            ]);
        }

        $delta = $movementType === 'IN' ? $quantity : -$quantity;
        $newQuantity = round((float) $balance->current_quantity + $delta, 3);

        if ($newQuantity < 0) {
            throw new \RuntimeException(
                Messages::EM027.' Available: '.rtrim(rtrim(number_format((float) $balance->current_quantity, 3), '0'), '.')
            );
        }

        $balance->current_quantity = $newQuantity;
        $balance->save();

        if ($itemType === self::ITEM_TYPE_INVENTORY_PRODUCT) {
            $product = InventoryProduct::query()->find($itemId);

            if ($product) {
                $product->current_stock = round(
                    (float) StockBalance::query()
                        ->where('item_type', self::ITEM_TYPE_INVENTORY_PRODUCT)
                        ->where('item_id', $itemId)
                        ->sum('current_quantity'),
                    3
                );
                $product->last_stock_update = now();
                $product->save();
            }
        }

        return $balance;
    }

    /**
     * newAverageCost = ((currentStock * currentAvgCost) + (newQty * newUnitCost)) / (currentStock + newQty)
     *
     * Falls back to just newUnitCost when there was no prior stock or no
     * prior average cost. Must be called AFTER applyMovement(), since it
     * derives "stock before this receipt" from the already-updated
     * current_stock. Inventory-Product-only — Product and Combo carry no
     * average_cost column to update.
     */
    public function updateAverageCost(InventoryProduct $product, float $baseQuantity, float $baseUnitCost): void
    {
        if ($baseUnitCost <= 0) {
            return;
        }

        $stockBeforeThisReceipt = (float) $product->current_stock - $baseQuantity;
        $currentAvg = $product->average_cost !== null ? (float) $product->average_cost : null;

        $newAvg = ($stockBeforeThisReceipt > 0 && $currentAvg !== null)
            ? ((($stockBeforeThisReceipt * $currentAvg) + ($baseQuantity * $baseUnitCost)) / ($stockBeforeThisReceipt + $baseQuantity))
            : $baseUnitCost;

        $product->average_cost = round($newAvg, 2);
        $product->last_purchase_cost = round($baseUnitCost, 2);
        $product->save();
    }

    /** Current on-hand quantity for an item at a warehouse. */
    public function currentQuantity(string $itemType, string $itemId, string $warehouseId): float
    {
        return (float) (StockBalance::query()
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->value('current_quantity') ?? 0);
    }

    /** Current on-hand quantity for an item, summed across every warehouse. */
    public function totalQuantity(string $itemType, string $itemId): float
    {
        return (float) StockBalance::query()
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->sum('current_quantity');
    }
}
