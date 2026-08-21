<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality — see CreateProductRequest. */
class UpdateProductRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'description' => ['IsString', 'IsOptional'],
            'uom_id' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'unit_price' => ['IsNotEmpty', 'IsNumber', 'IsOptional'],
            'cost_price' => ['IsNumber', 'IsOptional'],
            'min_stock' => ['IsNumber', 'IsOptional'],
            'low_stock_alert' => ['IsNumber', 'IsOptional'],
            'is_stockable' => ['IsBoolean', 'IsOptional'],
            'is_active' => ['IsNotEmpty', 'IsBoolean', 'IsOptional'],
        ];
    }
}
