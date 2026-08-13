<?php

namespace App\Support\Enums;

/** Port of the PaymentType enum in graspcraft_backend/src/utils/index.ts. */
enum PaymentType: string
{
    case CARD = 'card';
    case CASH = 'cash';
    case QR = 'qr';
    case ALL = 'all';
}
