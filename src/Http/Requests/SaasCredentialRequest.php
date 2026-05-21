<?php

namespace Webkul\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaasCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shopUrl' => ['required', 'string', 'max:255'],
            'accessToken' => ['required', 'string'],
            'unopim_client_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
