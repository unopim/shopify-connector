<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CredentialForm extends FormRequest
{
    /**
     * Shopify's Admin API is only served from the store's permanent myshopify.com domain.
     */
    public const SHOP_URL_PATTERN = '/^https?:\/\/[a-z0-9][a-z0-9-]*\.myshopify\.com\/?\z/i';

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'shopUrl'      => ['required', 'url:http,https', 'regex:'.self::SHOP_URL_PATTERN, 'unique:wk_shopify_credentials_config'],
            'accessToken'  => ['nullable'],
            'clientId'     => ['required'],
            'clientSecret' => ['required'],
            'apiVersion'   => ['required'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shopUrl.regex' => trans('shopify::app.shopify.credential.shop-url-myshopify'),
        ];
    }
}
