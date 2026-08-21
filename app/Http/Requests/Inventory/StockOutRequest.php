<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;
use App\Services\Inventory\StockService;

/**
 * New functionality, not a port of a Node DTO — see CreateProductRequest.
 *
 * item_type/item_id select which of the three stocked catalogs this
 * movement is against (StockService::ITEM_TYPES). uom_id only ever applies
 * to INVENTORY_PRODUCT, and is optional there too (defaults to the
 * product's own unit).
 */
class StockOutRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'item_type' => ['IsNotEmpty', ['IsEnum', StockService::ITEM_TYPES]],
            'item_id' => ['IsNotEmpty', 'IsString'],
            'warehouse_id' => ['IsNotEmpty', 'IsString'],
            'quantity' => ['IsNotEmpty', 'IsPositive'],
            'uom_id' => ['IsString', 'IsOptional'],
            'reason' => ['IsNotEmpty', ['IsEnum', ['sale', 'damaged', 'expired', 'wastage', 'other']]],
            'reference_number' => ['IsString', 'IsOptional'],
            'notes' => ['IsString', 'IsOptional'],
        ];
    }
}
