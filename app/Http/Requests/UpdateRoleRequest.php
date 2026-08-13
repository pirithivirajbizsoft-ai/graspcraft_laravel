<?php

namespace App\Http\Requests;

/** Port of UpdateRoleDto — PartialType(CreateRoleDto). */
class UpdateRoleRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'role_name' => ['IsString', 'IsNotEmpty', 'IsOptional'],
            'user_id' => ['IsString', 'IsNotEmpty', 'IsOptional'],
            'kiosk_ids' => ['IsArray', 'IsNotEmpty', 'IsOptional'],
            'is_active' => ['IsBoolean', 'IsNotEmpty', 'IsOptional'],
        ];
    }
}
