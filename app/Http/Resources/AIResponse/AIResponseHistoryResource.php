<?php

declare(strict_types=1);

namespace App\Http\Resources\AIResponse;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIResponseHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'content' => $this->content,
            'tone' => $this->tone,
            'language' => $this->language,
            'brand_voice_id' => $this->brand_voice_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
