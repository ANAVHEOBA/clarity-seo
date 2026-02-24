<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppleAppStoreAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'apple_app_store_account_id' => ['nullable', 'integer', 'exists:apple_app_store_accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'app_store_id' => ['nullable', 'string', 'max:64'],
            'bundle_id' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'apple_app_store_account_id.exists' => 'The selected App Store account does not exist.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('country_code') && is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper($this->input('country_code'))]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasAppStoreId = filled($this->input('app_store_id'));
            $hasBundleId = filled($this->input('bundle_id'));

            if (! $hasAppStoreId && ! $hasBundleId) {
                $validator->errors()->add('app_store_id', 'Either app_store_id or bundle_id is required.');
            }
        });
    }
}
