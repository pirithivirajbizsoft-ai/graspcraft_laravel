<?php

namespace App\Http\Requests;

/** Port of UpdatePcAmtMasterDto — PartialType(CreatePcAmtMasterDto). */
class UpdatePcAmtMasterRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'photo_count' => ['IsNumber', 'IsNotEmpty', 'IsOptional'],
            'price' => ['IsNumber', 'IsNotEmpty', 'IsOptional'],
        ];
    }
}
