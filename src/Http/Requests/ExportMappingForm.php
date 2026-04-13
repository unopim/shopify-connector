<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Core\Rules\BooleanString;

class ExportMappingForm extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'default_taxable' => ['sometimes', 'nullable', new BooleanString],
            'default_inventoryPolicy' => ['sometimes', 'nullable', new BooleanString],
            'default_inventoryTracked' => ['sometimes', 'nullable', new BooleanString],
            'default_price' => 'sometimes|nullable|numeric',
            'default_weight' => 'sometimes|nullable|numeric',
            'default_inventoryQuantity' => 'sometimes|nullable|numeric',
            'default_compareAtPrice' => 'sometimes|nullable|numeric',
            'default_cost' => 'sometimes|nullable|numeric',
        ];
    }
}
