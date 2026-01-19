<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

class StorePlatformCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:facebook,google,bing,youtube'],
            'access_token' => ['required', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'page_id' => ['required', 'string'], // For YouTube this will be the Channel ID
            'page_access_token' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.in' => 'The platform must be one of: facebook, google, bing, youtube.',
            'access_token.required' => 'An access token is required to connect the platform.',
            'page_id.required' => 'A page ID (or Channel ID) is required to connect the platform.',
        ];
    }
}
