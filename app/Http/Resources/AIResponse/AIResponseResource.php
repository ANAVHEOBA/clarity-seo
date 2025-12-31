<?php

declare(strict_types=1);

namespace App\Http\Resources\AIResponse;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'content' => $this->content,
            'status' => $this->status,
            'ai_generated' => $this->ai_generated,
            'brand_voice_id' => $this->brand_voice_id,
            'tone' => $this->tone,
            'language' => $this->language,
            'approved_by' => $this->when($this->approved_by, fn () => [
                'id' => $this->approver?->id,
                'name' => $this->approver?->name,
            ]),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
