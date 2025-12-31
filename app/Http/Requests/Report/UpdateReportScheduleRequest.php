<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReportScheduleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'type' => ['sometimes', 'string', 'in:reviews,sentiment,summary,trends,location_comparison,reviews_detailed'],
            'format' => ['sometimes', 'string', 'in:pdf,excel,csv'],
            'frequency' => ['sometimes', 'string', 'in:daily,weekly,monthly'],
            'day_of_week' => ['sometimes', 'nullable', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:28'],
            'time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['sometimes', 'nullable', 'string'],
            'period' => ['sometimes', 'nullable', 'string', 'in:last_7_days,last_30_days,last_quarter,year_to_date'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:report_templates,id'],
            'location_ids' => ['sometimes', 'nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'recipients' => ['sometimes', 'array', 'min:1'],
            'recipients.*' => ['email'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
