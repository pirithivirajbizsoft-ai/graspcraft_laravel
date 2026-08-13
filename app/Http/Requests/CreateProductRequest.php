<?php

namespace App\Http\Requests;

use App\Support\Enums\ProductType;

/** Port of CreateProductDto in admin/products/dto/create-product.dto.ts. */
class CreateProductRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'product_name' => ['IsNotEmpty', 'IsString'],
            // Declared as a STRING on the DTO even though the column is integer.
            'price' => ['IsNotEmpty', 'IsString'],
            'product_type' => ['IsNotEmpty', ['IsEnum', array_column(ProductType::cases(), 'value')]],
            'photo_limit' => ['IsNotEmpty', 'IsNumber'],
            'size' => ['IsNotEmpty', 'IsString'],
            'description' => ['IsOptional', 'IsString'],
            'img_url' => ['IsNotEmpty', 'IsString'],
            'status' => ['IsNotEmpty', 'IsBoolean'],
            'certificate_name' => ['IsOptional', 'IsString'],
        ];
    }
}
