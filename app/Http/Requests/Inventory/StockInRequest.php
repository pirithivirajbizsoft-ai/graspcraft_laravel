<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;
use App\Services\Inventory\StockService;

/**
 * New functionality, not a port of a Node DTO — see CreateProductRequest.
 *
 * item_type/item_id select which of the three stocked catalogs this
 * movement is against (StockService::ITEM_TYPES). uom_id is only ever
 * meaningful for INVENTORY_PRODUCT; whether it's required for that type is
 * enforced in StockInService, not here, since ClassValidatorRequest has no
 * clean per-value conditional-required constraint.
 */
class StockInRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'item_type' => ['IsNotEmpty', ['IsEnum', StockService::ITEM_TYPES]],
            'item_id' => ['IsNotEmpty', 'IsString'],
            'warehouse_id' => ['IsNotEmpty', 'IsString'],
            'quantity' => ['IsNotEmpty', 'IsPositive'],
            'uom_id' => ['IsString', 'IsOptional'],
            'unit_cost' => ['IsNumber', 'IsOptional'],
            'batch_number' => ['IsString', 'IsOptional'],
            'expiry_date' => ['IsString', 'IsOptional'],
            'movement_subtype' => ['IsNotEmpty', ['IsEnum', ['PURCHASE', 'ADJUSTMENT', 'OPENING_STOCK']]],
            'reference_number' => ['IsString', 'IsOptional'],
            'notes' => ['IsString', 'IsOptional'],
        ];
    }
}
