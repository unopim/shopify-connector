<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Shopify\Support\ProFeatures;

class MetaFieldForm extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ownerType' => ['required'],
            'code'      => ['required_unless:reference_mode,1'],
            'type'      => ['nullable', Rule::notIn(resolve(ProFeatures::class)->lockedMetafieldTypes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'type.not_in' => trans('shopify::app.shopify.pro.types-note'),
        ];
    }
}
