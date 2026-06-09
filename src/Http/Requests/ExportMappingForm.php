<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Core\Rules\BooleanString;
use Webkul\Shopify\Helpers\ShopifyFields;

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
            'title' => ['required_without:default_title'],
            'default_taxable' => ['sometimes', 'nullable', new BooleanString],
            'default_inventoryPolicy' => ['sometimes', 'nullable', new BooleanString],
            'default_inventoryTracked' => ['sometimes', 'nullable', new BooleanString],
            'default_price' => 'sometimes|nullable|numeric',
            'default_weight' => 'sometimes|nullable|numeric',
            'default_inventoryQuantity' => 'sometimes|nullable|numeric',
            'default_compareAtPrice' => 'sometimes|nullable|numeric',
            'default_cost' => 'sometimes|nullable|numeric',
            'status' => ['required', Rule::in((new ShopifyFields)->getStatusEnumValues())],
            'unit_price_quantity_value' => ['sometimes', 'nullable', 'string'],
            'unit_price_quantity_unit' => ['sometimes', 'nullable', 'string'],
            'unit_price_reference_value' => ['sometimes', 'nullable', 'numeric'],
            'unit_price_reference_unit' => ['sometimes', 'nullable', Rule::in(array_merge((new ShopifyFields)->getUnitPriceUnitValues(), ['AUTO']))],
        ];
    }

    /**
     * Get the validation messages for the defined rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'title.required_without' => trans('shopify::app.shopify.export.mapping.validation.name_required'),
            'status.required' => trans('shopify::app.shopify.export.mapping.validation.status_required'),
        ];
    }
}
