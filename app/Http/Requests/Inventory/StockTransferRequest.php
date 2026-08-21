<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;
use App\Services\Inventory\StockService;

/**
 * New functionality, not a port of a Node DTO — see CreateProductRequest.
 *
 * from_warehouse_id != to_warehouse_id is enforced in StockTransferService,
 * not here — ClassValidatorRequest has no clean "differs from another
 * field" constraint (matching how Uom's base_unit != self is also checked
 * in its service, not its request).
 */
class StockTransferRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'item_type' => ['IsNotEmpty', ['IsEnum', StockService::ITEM_TYPES]],
            'item_id' => ['IsNotEmpty', 'IsString'],
            'from_warehouse_id' => ['IsNotEmpty', 'IsString'],
            'to_warehouse_id' => ['IsNotEmpty', 'IsString'],
            'quantity' => ['IsNotEmpty', 'IsPositive'],
            'uom_id' => ['IsString', 'IsOptional'],
            'batch_number' => ['IsString', 'IsOptional'],
            'expiry_date' => ['IsString', 'IsOptional'],
            'transfer_reason' => ['IsNotEmpty', 'IsString'],
            'notes' => ['IsString', 'IsOptional'],
        ];
    }
}
