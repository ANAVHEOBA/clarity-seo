<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', 'in:reviews,sentiment,summary,trends,location_comparison,reviews_detailed'],
            'format' => ['sometimes', 'string', 'in:pdf,excel,csv'],
            'sections' => ['sometimes', 'nullable', 'array'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'branding.logo_url' => ['sometimes', 'nullable', 'string'],
            'branding.primary_color' => ['sometimes', 'nullable', 'string'],
            'branding.secondary_color' => ['sometimes', 'nullable', 'string'],
            'branding.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.footer_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
