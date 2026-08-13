<?php

namespace App\Http\Requests;

/** Port of CreateRoleDto in roles/dto/create-role.dto.ts. */
class CreateRoleRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'role_name' => ['IsString', 'IsNotEmpty'],
            'user_id' => ['IsString', 'IsNotEmpty'],
            'kiosk_ids' => ['IsArray', 'IsNotEmpty'],
            // Typed `is_active: string` on the DTO but decorated @IsBoolean, so
            // a boolean is what actually passes.
            'is_active' => ['IsBoolean', 'IsNotEmpty'],
        ];
    }
}
