<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality, not a port of a Node DTO — see CreateProductRequest. */
class CreateUomRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString'],
            'uom_short' => ['IsNotEmpty', 'IsString'],
            'base_unit' => ['IsString', 'IsOptional'],
            'conversion_factor' => ['IsNumber', 'IsOptional'],
            'is_active' => ['IsBoolean', 'IsOptional'],
        ];
    }
}
