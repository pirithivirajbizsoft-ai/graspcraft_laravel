<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/**
 * New functionality, not a port of a Node DTO — see CreateProductRequest.
 *
 * item_id is always an Inventory Item (see StockOutService) — Stock Out has
 * no item-type choice. uom_id is optional, defaulting to the product's own
 * unit.
 */
class StockOutRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
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
