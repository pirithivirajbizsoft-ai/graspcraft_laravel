<?php

namespace App\Services\Inventory;

use App\Models\BomItem;
use App\Models\InventoryProduct;

/**
 * A rejection the user can act on, as opposed to an unexpected failure.
 * Mirrors App\Services\CommissionValidationError — the controller surfaces
 * its message instead of the generic EM008.
 */
class BomValidationError extends \RuntimeException {}

/**
 * Shared BOM persistence for Product and Combo — both owners use the same
 * wholesale-replace shape, so this avoids duplicating it twice. New
 * functionality, not a port of anything in graspcraft_backend.
 */
class BomService
{
    /**
     * Replaces every BOM row for one owner (a Product or a Combo) with the
     * submitted set. Delete-and-reinsert, same as ProdCombMap/
     * ComboUserCommission — a BOM line has no independent identity worth
     * diffing. Caller decides whether to call this at all (see
     * array_key_exists('bom_items', $dto) in ProductsService/ComboService)
     * — an empty array here means "clear every row", matching
     * user_commissions, not product_ids' documented empty-array-ignored
     * quirk (this is new functionality, not something to inherit that from).
     *
     * @param  string  $ownerType  'PRODUCT' | 'COMBO' — see the morph map
     *                             in AppServiceProvider::boot()
     * @param  array<int, array{inventory_product_id?: mixed, quantity?: mixed}>|null  $bomItems
     *
     * @throws BomValidationError with a message the admin can act on
     */
    public function replaceFor(string $ownerType, string $ownerId, ?array $bomItems): void
    {
        $rows = $this->validateAndBuildRows($ownerType, $ownerId, $bomItems);

        BomItem::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->delete();

        foreach ($rows as $row) {
            BomItem::create($row);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $bomItems
     * @return array<int, array<string, mixed>>
     */
    private function validateAndBuildRows(string $ownerType, string $ownerId, ?array $bomItems): array
    {
        if (empty($bomItems)) {
            return [];
        }

        $seen = [];
        foreach ($bomItems as $row) {
            $inventoryProductId = $row['inventory_product_id'] ?? null;

            if (! $inventoryProductId) {
                throw new BomValidationError('Each BOM component must reference an inventory item.');
            }

            if (in_array($inventoryProductId, $seen, true)) {
                throw new BomValidationError('The same inventory item is listed more than once.');
            }

            $seen[] = $inventoryProductId;

            if (! is_numeric($row['quantity'] ?? null) || (float) $row['quantity'] <= 0) {
                throw new BomValidationError('Quantity must be a number greater than zero.');
            }
        }

        // One query for the whole set rather than per row.
        $existingIds = InventoryProduct::query()->whereIn('id', $seen)->pluck('id')->all();

        foreach ($seen as $inventoryProductId) {
            if (! in_array($inventoryProductId, $existingIds, true)) {
                throw new BomValidationError('One of the selected inventory items no longer exists.');
            }
        }

        return array_map(fn ($row) => [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'inventory_product_id' => $row['inventory_product_id'],
            'quantity' => $row['quantity'],
        ], $bomItems);
    }
}
