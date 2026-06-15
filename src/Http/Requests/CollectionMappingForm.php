<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Shopify\Helpers\ShopifyFields;

class CollectionMappingForm extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => ['required'],
            'sort_order' => ['sometimes', 'nullable', Rule::in((new ShopifyFields)->getCollectionSortOrderValues())],
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
            'title.required' => trans('shopify::app.shopify.export.mapping.collection.validation.title_required'),
        ];
    }
}
