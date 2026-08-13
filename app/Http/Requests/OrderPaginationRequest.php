<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * Port of OrderPaginationDto in users/dto/create-user.dto.ts.
 *
 * Used by POST /users/getall-orders and POST /admin-dashboard.
 *
 * The date rules look wrong, and they are — but they REJECT REAL REQUESTS, so
 * they have to be reproduced rather than tidied. The Nest declaration is:
 *
 *     @ValidateIf((o) => o.to_date !== null && o.to_date !== '' && o.to_date == undefined)
 *
 *     @IsNotEmpty({ message: 'from_date should not be empty if to_date is provided' })
 *
 *     @IsString()
 *     from_date: string;
 *
 * and the mirror image for to_date. Note `o.to_date == undefined` — the guard
 * fires when to_date is ABSENT, which is the opposite of what the message says.
 * Worked through:
 *
 *   both absent        -> both guards fire, both fields fail  -> 400
 *   only from_date set -> the to_date guard fires, to_date fails -> 400
 *   only to_date set   -> the from_date guard fires, from_date fails -> 400
 *   both present       -> neither guard fires -> passes
 *
 * So in practice BOTH dates are mandatory on this endpoint, and a request with
 * neither is a 400 — which is what the live Nest API does today. The panel
 * always sends both.
 */
class OrderPaginationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search_text' => ['sometimes', 'nullable', 'string'],
            'page_no' => ['sometimes', 'nullable', 'numeric'],
            'limit' => ['sometimes', 'nullable', 'numeric'],

            'user_id' => ['sometimes', 'nullable', 'array'],
            'staff_id' => ['sometimes', 'nullable', 'string'],
            'user_type' => ['required', 'string'],
            'logined_userId' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');

            // `o.x == undefined` — absent or null, matching JS loose equality.
            $toDateAbsent = $toDate === null;
            $fromDateAbsent = $fromDate === null;

            /*
             * Message order is part of the payload the panel receives, and
             * class-validator emits the decorators bottom-up: @IsString before
             * @IsNotEmpty. Keep the two adds in that order.
             */
            if ($toDateAbsent) {
                if (! is_string($fromDate)) {
                    $validator->errors()->add('from_date', 'from_date must be a string');
                }

                if ($fromDate === null || $fromDate === '') {
                    $validator->errors()->add(
                        'from_date',
                        'from_date should not be empty if to_date is provided',
                    );
                }
            }

            if ($fromDateAbsent) {
                if (! is_string($toDate)) {
                    $validator->errors()->add('to_date', 'to_date must be a string');
                }

                if ($toDate === null || $toDate === '') {
                    $validator->errors()->add(
                        'to_date',
                        'to_date should not be empty if from_date is provided',
                    );
                }
            }
        });
    }
}
