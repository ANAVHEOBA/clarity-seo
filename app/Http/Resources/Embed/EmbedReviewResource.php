<?php

declare(strict_types=1);

namespace App\Http\Resources\Embed;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbedReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_name' => $this->author_name,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'platform' => $this->platform,
        ];
    }
}
