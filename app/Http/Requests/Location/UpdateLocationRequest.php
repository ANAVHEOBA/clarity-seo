<?php

declare(strict_types=1);

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'regex:/^[\d\s\-\+\(\)]+$/'],
            'website' => ['nullable', 'url'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'primary_category' => ['nullable', 'string', 'max:100'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:100'],
            'business_hours' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,inactive'],
            'apple_app_store_app_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number format is invalid.',
        ];
    }
}
