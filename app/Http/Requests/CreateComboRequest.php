<?php

namespace App\Http\Requests;

/** Port of CreateComboDto in admin/combo/dto/create-combo.dto.ts. */
class CreateComboRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            // @IsArray() @IsString({ each: true }) — ArrayNotEmpty is commented
            // out in the DTO, so an empty array is accepted.
            'product_ids' => ['IsArray', ['IsString', 'each']],
            'combo_name' => ['IsString', 'IsNotEmpty'],
            'price' => ['IsNumber', 'IsNotEmpty'],
            'description' => ['IsString', 'IsOptional'],
            'img_url' => ['IsString', 'IsNotEmpty'],
            'status' => ['IsBoolean', 'IsNotEmpty'],
            /*
             * Declared purely so ValidationPipe({ whitelist: true }) does not
             * strip it — the per-row rules (percent <= 100, eligible user type)
             * are enforced in ComboService.
             */
            'user_commissions' => ['IsArray', 'IsOptional'],
        ];
    }
}
