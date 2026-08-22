<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/**
 * New functionality, not a port of a Node DTO — see CreateProductRequest.
 *
 * item_id is always an Inventory Item (see StockInService) — Stock In has
 * no item-type choice. uom_id is required; that's enforced in
 * StockInService, not here, since ClassValidatorRequest has no clean
 * per-value conditional-required constraint.
 */
class StockInRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
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
