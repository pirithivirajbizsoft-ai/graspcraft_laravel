<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/**
 * New functionality, not a port of a Node DTO — validated with the same
 * ClassValidatorRequest base every other module uses, for a consistent
 * error shape and payload-whitelisting behaviour across the API.
 *
 * product_code and current_stock are deliberately absent: the code is
 * system-generated and stock only ever changes through Stock In/Out.
 */
class CreateProductRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString'],
            'description' => ['IsString', 'IsOptional'],
            'uom_id' => ['IsNotEmpty', 'IsString'],
            'unit_price' => ['IsNotEmpty', 'IsNumber'],
            'cost_price' => ['IsNumber', 'IsOptional'],
            'min_stock' => ['IsNumber', 'IsOptional'],
            'low_stock_alert' => ['IsNumber', 'IsOptional'],
            'is_stockable' => ['IsBoolean', 'IsOptional'],
            'is_active' => ['IsNotEmpty', 'IsBoolean'],
        ];
    }
}
