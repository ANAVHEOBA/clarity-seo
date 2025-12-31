<x-mail::message>
# Your Report is Ready

@if($customMessage)
{{ $customMessage }}
@else
Your {{ $report->type }} report has been generated and is ready for download.
@endif

**Report Details:**
- **Type:** {{ ucfirst(str_replace('_', ' ', $report->type)) }}
- **Format:** {{ strtoupper($report->format) }}
- **Generated:** {{ $report->completed_at?->format('M d, Y H:i') }}

<x-mail::button :url="$downloadUrl">
Download Report
</x-mail::button>

This download link will expire in 30 days.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
