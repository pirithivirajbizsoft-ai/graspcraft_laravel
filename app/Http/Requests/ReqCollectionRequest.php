<?php

namespace App\Http\Requests;

/** Port of ReqCollectionDto in aws/dto/create-aw.dto.ts. */
class ReqCollectionRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'req_Col_id' => ['IsNotEmpty', 'IsString'],
        ];
    }
}
