<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality — see CreateProductRequest. */
class UpdateUomRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'uom_short' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'base_unit' => ['IsString', 'IsOptional'],
            'conversion_factor' => ['IsNumber', 'IsOptional'],
            'is_active' => ['IsBoolean', 'IsOptional'],
        ];
    }
}
