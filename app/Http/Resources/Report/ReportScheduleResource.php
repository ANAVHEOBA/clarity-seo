<?php

declare(strict_types=1);

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'format' => $this->format,
            'frequency' => $this->frequency,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'time' => $this->time_of_day ? substr($this->time_of_day, 0, 5) : null,
            'timezone' => $this->timezone,
            'period' => $this->period,
            'location_ids' => $this->location_ids,
            'filters' => $this->filters,
            'branding' => $this->branding,
            'options' => $this->options,
            'recipients' => $this->recipients,
            'is_active' => $this->is_active,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
