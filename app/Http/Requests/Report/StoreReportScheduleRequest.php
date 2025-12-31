<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportScheduleRequest extends FormRequest
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
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'day_of_week' => ['required_if:frequency,weekly', 'nullable', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'day_of_month' => ['required_if:frequency,monthly', 'nullable', 'integer', 'min:1', 'max:28'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['sometimes', 'nullable', 'string'],
            'period' => ['sometimes', 'nullable', 'string', 'in:last_7_days,last_30_days,last_quarter,year_to_date'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:report_templates,id'],
            'location_ids' => ['sometimes', 'nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'frequency.in' => 'The frequency must be one of: daily, weekly, monthly.',
            'day_of_week.required_if' => 'The day of week is required for weekly schedules.',
            'day_of_week.in' => 'The day of week must be a valid day name.',
            'day_of_month.required_if' => 'The day of month is required for monthly schedules.',
            'day_of_month.min' => 'The day of month must be between 1 and 28.',
            'day_of_month.max' => 'The day of month must be between 1 and 28.',
            'time.regex' => 'The time must be in HH:MM format.',
            'recipients.required' => 'At least one recipient is required.',
            'recipients.*.email' => 'Each recipient must be a valid email address.',
        ];
    }
}
