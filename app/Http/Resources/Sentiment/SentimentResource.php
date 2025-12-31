<?php

declare(strict_types=1);

namespace App\Http\Resources\Sentiment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SentimentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score,
            'emotions' => $this->emotions ?? [],
            'topics' => $this->topics ?? [],
            'keywords' => $this->keywords ?? [],
            'language' => $this->language,
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
