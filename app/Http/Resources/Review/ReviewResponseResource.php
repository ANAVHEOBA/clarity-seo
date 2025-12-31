<?php

declare(strict_types=1);

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'status' => $this->status,
            'ai_generated' => $this->ai_generated,
            'published_at' => $this->published_at?->toIso8601String(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
