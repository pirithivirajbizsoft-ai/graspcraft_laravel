<?php

namespace App\Http\Requests;

/** Port of CreateFrameDto in admin/frames/dto/create-frame.dto.ts. */
class CreateFrameRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'frame_name' => ['IsNotEmpty', 'IsString'],
            /*
             * Declared on the DTO but there is no photo_limit column on frames,
             * so it validates and is then dropped by the model's $fillable.
             * Listed here so the whitelist matches the Nest one exactly.
             */
            'photo_limit' => ['IsNumber', 'IsOptional'],
            'img_url' => ['IsNotEmpty', 'IsString'],
            'status' => ['IsNotEmpty', 'IsBoolean'],
        ];
    }
}
