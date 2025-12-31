<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ReportReady extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Report $report,
        public ?string $customSubject = null,
        public ?string $customMessage = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->customSubject ?? 'Your Report is Ready: '.$this->report->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-ready',
            with: [
                'report' => $this->report,
                'customMessage' => $this->customMessage,
                'downloadUrl' => route('api.v1.reports.download', [
                    'tenant' => $this->report->tenant_id,
                    'report' => $this->report->id,
                ]),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->report->file_path || ! Storage::disk('local')->exists($this->report->file_path)) {
            return [];
        }

        return [
            Attachment::fromStorage($this->report->file_path)
                ->as($this->report->file_name)
                ->withMime($this->getMimeType()),
        ];
    }

    private function getMimeType(): string
    {
        return match ($this->report->format) {
            'pdf' => 'application/pdf',
            'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
