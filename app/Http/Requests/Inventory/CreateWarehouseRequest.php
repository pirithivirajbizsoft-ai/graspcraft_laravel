<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality, not a port of a Node DTO — see CreateProductRequest. */
class CreateWarehouseRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'name' => ['IsNotEmpty', 'IsString'],
            'description' => ['IsString', 'IsOptional'],
            'is_active' => ['IsBoolean', 'IsOptional'],
        ];
    }
}
