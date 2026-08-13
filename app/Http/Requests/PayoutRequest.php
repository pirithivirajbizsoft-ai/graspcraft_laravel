<?php

namespace App\Http\Requests;

/** Port of PayoutDto in admin/commission/dto/commission.dto.ts. */
class PayoutRequest extends ClassValidatorRequest
{
    protected function constraints(): array
    {
        return [
            'commission_ids' => ['IsArray', 'ArrayNotEmpty', ['IsString', 'each']],
            /** The admin performing the payout. */
            'paid_by' => ['IsOptional', 'IsString'],
            /** Date the money actually moved. Defaults to today when omitted. */
            'payout_date' => ['IsOptional', 'IsString'],
            'payment_mode' => ['IsOptional', ['IsEnum', ['cash', 'online', 'cheque']]],
            /** Who handed the money over. Optional. */
            'released_by' => ['IsOptional', 'IsString'],
            'reference_no' => ['IsOptional', 'IsString'],
            'reference_date' => ['IsOptional', 'IsString'],
            'remarks' => ['IsOptional', 'IsString'],
        ];
    }
}
