<?php

namespace App\Http\Requests;

/** Port of CreateDiscountDto in discount/dto/create-discount.dto.ts. */
class CreateDiscountRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'discount_code' => ['IsNotEmpty', 'IsString'],
            'discount_amount' => ['IsNotEmpty', 'IsNumber'],
            'description' => ['IsOptional', 'IsString'],
        ];
    }
}
