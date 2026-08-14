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
 * The two clauses before it, `!== null` and `!== ''`, mean a key that is present
 * and null (or empty) does NOT count as absent. Worked through:
 *
 *   both keys absent      -> both guards fire, both fields fail -> 400
 *   from_date sent, to_date absent -> from_date's guard fires; a non-empty
 *                            string passes it, so -> 200
 *   to_date sent, from_date absent -> mirror image -> 200
 *   both keys present (any value, including null or '') -> no guard -> 200
 *
 * So only a request omitting BOTH keys is rejected. Sending them as null or ''
 * is accepted, which is what the panel does: the order filter initialises its
 * date controls to '' and the report filters initialise theirs to null.
 *
 * An earlier revision of this class read `$this->input('to_date') === null` for
 * absence. That conflated null with absent and 400'd both of those panel pages
 * on first load, where the Node API answers 200.
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

            /*
             * The guard is `o.to_date !== null && o.to_date !== '' && o.to_date == undefined`.
             * The first two clauses exclude null and '' outright, so the whole
             * condition is true only when the key is genuinely ABSENT from the
             * body — `undefined` in JS. A present-but-null value skips it.
             *
             * Laravel collapses that distinction twice over: `$this->input('to_date')`
             * returns null for both cases, and ConvertEmptyStringsToNull has already
             * rewritten '' to null by this point. has() is checked instead, because it
             * reports key presence rather than value emptiness.
             *
             * Getting this wrong is not academic: the panel's report filters
             * initialise their date controls to null and its order filter to '',
             * so treating either as absent 400s both pages on first load, where
             * the Node API answers 200.
             */
            $toDateAbsent = ! $this->has('to_date');
            $fromDateAbsent = ! $this->has('from_date');

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
