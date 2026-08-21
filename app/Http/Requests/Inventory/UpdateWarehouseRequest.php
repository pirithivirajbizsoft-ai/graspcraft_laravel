<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality — see CreateProductRequest. */
class UpdateWarehouseRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'description' => ['IsString', 'IsOptional'],
            'is_active' => ['IsBoolean', 'IsOptional'],
        ];
    }
}
