<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
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
            'type' => ['sometimes', 'required_without:template_id', 'string', 'in:reviews,sentiment,summary,trends,location_comparison,reviews_detailed'],
            'format' => ['required', 'string', 'in:pdf,excel,csv'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:report_templates,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'location_ids' => ['sometimes', 'nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'period' => ['sometimes', 'nullable', 'string', 'in:last_7_days,last_30_days,last_quarter,year_to_date'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'branding.logo_url' => ['sometimes', 'nullable', 'string'],
            'branding.primary_color' => ['sometimes', 'nullable', 'string'],
            'branding.secondary_color' => ['sometimes', 'nullable', 'string'],
            'branding.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.footer_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.white_label' => ['sometimes', 'nullable', 'boolean'],
            'include_charts' => ['sometimes', 'nullable', 'boolean'],
            'include_headers' => ['sometimes', 'nullable', 'boolean'],
            'multi_sheet' => ['sometimes', 'nullable', 'boolean'],
            'send_email' => ['sometimes', 'nullable', 'boolean'],
            'recipients' => ['required_if:send_email,true', 'nullable', 'array'],
            'recipients.*' => ['email'],
            'email_subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'The report type must be one of: reviews, sentiment, summary, trends, location_comparison, reviews_detailed.',
            'format.in' => 'The report format must be one of: pdf, excel, csv.',
            'date_to.after_or_equal' => 'The end date must be after or equal to the start date.',
            'recipients.required_if' => 'Recipients are required when sending email.',
            'recipients.*.email' => 'Each recipient must be a valid email address.',
        ];
    }
}
