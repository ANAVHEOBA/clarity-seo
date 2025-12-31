<?php

declare(strict_types=1);

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'format' => $this->format,
            'status' => $this->status,
            'progress' => $this->progress,
            'file_path' => $this->file_path,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'date_from' => $this->date_from?->format('Y-m-d'),
            'date_to' => $this->date_to?->format('Y-m-d'),
            'period' => $this->period,
            'location_id' => $this->location_id,
            'location_ids' => $this->location_ids,
            'filters' => $this->filters,
            'branding' => $this->branding,
            'options' => $this->options,
            'error_message' => $this->when($this->status === 'failed', $this->error_message),
            'download_url' => $this->when(
                $this->status === 'completed' && $this->file_path,
                fn () => route('api.v1.reports.download', ['tenant' => $this->tenant_id, 'report' => $this->id])
            ),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
