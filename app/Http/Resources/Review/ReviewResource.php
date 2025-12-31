<?php

declare(strict_types=1);

namespace App\Http\Resources\Review;

use App\Http\Resources\Location\LocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'external_id' => $this->external_id,
            'author_name' => $this->author_name,
            'author_image' => $this->author_image,
            'rating' => $this->rating,
            'content' => $this->content,
            'published_at' => $this->published_at?->toIso8601String(),
            'location' => new LocationResource($this->whenLoaded('location')),
            'response' => new ReviewResponseResource($this->whenLoaded('response')),
            'sentiment' => $this->when($this->relationLoaded('sentiment') && $this->sentiment, fn () => [
                'sentiment' => $this->sentiment->sentiment,
                'sentiment_score' => $this->sentiment->sentiment_score,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
