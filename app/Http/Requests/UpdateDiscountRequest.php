<?php

namespace App\Http\Requests;

/** Port of UpdateDiscountDto — PartialType(CreateDiscountDto). */
class UpdateDiscountRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'discount_code' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'discount_amount' => ['IsNotEmpty', 'IsNumber', 'IsOptional'],
            'description' => ['IsOptional', 'IsString'],
        ];
    }
}
