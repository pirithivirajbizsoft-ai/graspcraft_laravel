<?php

namespace App\Http\Requests;

/** Port of UpdateFrameDto — PartialType(CreateFrameDto). */
class UpdateFrameRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'frame_name' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'photo_limit' => ['IsNumber', 'IsOptional'],
            'img_url' => ['IsNotEmpty', 'IsString', 'IsOptional'],
            'status' => ['IsNotEmpty', 'IsBoolean', 'IsOptional'],
        ];
    }
}
