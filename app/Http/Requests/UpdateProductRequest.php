<?php

namespace App\Http\Requests;

use App\Support\Enums\ProductType;

/** Port of UpdateProductDto — PartialType(CreateProductDto). */
class UpdateProductRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'product_name' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'price' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'product_type' => ['IsNotEmpty', ['IsEnum', array_column(ProductType::cases(), 'value')], 'IsOptional'],
            'photo_limit' => ['IsNotEmpty', 'IsNumber', 'IsOptional'],
            'size' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'description' => ['IsOptional', 'IsString'],
            'img_url' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'status' => ['IsNotEmpty', 'IsBoolean', 'IsOptional'],
            'certificate_name' => ['IsOptional', 'IsString'],
        ];
    }
}
