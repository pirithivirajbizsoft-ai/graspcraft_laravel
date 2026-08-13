<?php

namespace App\Http\Requests;

/**
 * Port of UpdateComboDto — `PartialType(CreateComboDto)` in Nest, which keeps
 * every decorator but makes each property optional.
 */
class UpdateComboRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'product_ids' => ['IsArray', ['IsString', 'each'], 'IsOptional'],
            'combo_name' => ['IsString', 'IsNotEmpty', 'IsOptional'],
            'price' => ['IsNumber', 'IsNotEmpty', 'IsOptional'],
            'description' => ['IsString', 'IsOptional'],
            'img_url' => ['IsString', 'IsNotEmpty', 'IsOptional'],
            'status' => ['IsBoolean', 'IsNotEmpty', 'IsOptional'],
            'user_commissions' => ['IsArray', 'IsOptional'],
        ];
    }
}
