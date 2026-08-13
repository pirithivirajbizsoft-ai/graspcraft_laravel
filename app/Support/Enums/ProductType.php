<?php

namespace App\Support\Enums;

/** Port of the ProductType enum in graspcraft_backend/src/utils/index.ts. */
enum ProductType: string
{
    case EMAIL = 'email';
    case MAGNET = 'magnet';
    case OTHERS = 'others';
    case ALL = 'all';
    case CERTIFICATE_LEFT_1 = 'certificate_left_1';
    case CERTIFICATE_LEFT_2 = 'certificate_left_2';
    case CERTIFICATE_RIGHT_1 = 'certificate_right_1';

    /** The three certificate variants share one handling branch in cart/photocart. */
    public static function isCertificate(?string $type): bool
    {
        return in_array($type, [
            self::CERTIFICATE_LEFT_1->value,
            self::CERTIFICATE_LEFT_2->value,
            self::CERTIFICATE_RIGHT_1->value,
        ], true);
    }
}
