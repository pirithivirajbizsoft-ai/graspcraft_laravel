<?php

namespace App\Http\Requests;

/** Port of CreatePcAmtMasterDto. */
class CreatePcAmtMasterRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'photo_count' => ['IsNumber', 'IsNotEmpty'],
            'price' => ['IsNumber', 'IsNotEmpty'],
        ];
    }
}
