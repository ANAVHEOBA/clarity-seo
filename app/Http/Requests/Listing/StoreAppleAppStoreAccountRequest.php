<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppleAppStoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'issuer_id' => ['required', 'uuid'],
            'key_id' => ['required', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'private_key' => ['required', 'string', 'min:80'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key_id.regex' => 'The API Key ID must be 10 uppercase letters/numbers (example: 365CZB3ST7).',
        ];
    }
}
