<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ClassValidatorRequest;

/** New functionality, not a port of a Node DTO — see MigrateStockRequest. */
class StockRequirementsRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'order_ids' => ['IsArray', 'ArrayNotEmpty', ['IsString', 'each']],
        ];
    }
}
