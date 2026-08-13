<?php

namespace App\Support\Enums;

/** Port of the UsersType enum in graspcraft_backend/src/utils/index.ts. */
enum UsersType: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case PHOTOGRAPHER = 'photographer';
    case KIOSK = 'kiosk';
    case MANAGER = 'manager';
    case ACCOUNT_MANAGER = 'account_manager';
    case STAFF = 'staff';
    case SUPERVISOR = 'supervisor';
    case CUSTOMER = 'customer';
}
