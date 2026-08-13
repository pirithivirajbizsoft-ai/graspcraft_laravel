<?php

namespace App\Http\Requests;

/**
 * Port of CreateProductSizeDto.
 *
 * Used for the update route too — the Nest controller types its update body as
 * CreateProductSizeDto rather than a PartialType, so both fields stay required.
 */
class CreateProductSizeRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'height' => ['IsNotEmpty', 'IsString'],
            'width' => ['IsNotEmpty', 'IsString'],
        ];
    }
}
