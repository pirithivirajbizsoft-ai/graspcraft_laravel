<?php

namespace App\Http\Requests;

/**
 * Port of PaginationDto in response.helper.ts.
 *
 * Every field is optional, and the services all default page_no to 1 and limit
 * to 10 when absent. A couple of services also treat `limit === 0 && page_no === 0`
 * as "no pagination at all" (ProductsService::findAll,
 * ObjectMasterService::findAll), which is why 0 has to stay a legal value rather
 * than being rejected by a `min:1` rule.
 */
class PaginationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search_text' => ['sometimes', 'nullable', 'string'],
            'page_no' => ['sometimes', 'nullable', 'numeric'],
            'limit' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
