<?php

namespace App\Support\Enums;

/** Port of the OrderStatus enum in graspcraft_backend/src/utils/index.ts. */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case ALL = 'all';
}
