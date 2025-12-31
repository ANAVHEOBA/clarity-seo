<?php

declare(strict_types=1);

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewStatsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'total_reviews' => $this->resource['total_reviews'],
            'average_rating' => $this->resource['average_rating'],
            'rating_distribution' => $this->resource['rating_distribution'],
            'by_platform' => $this->resource['by_platform'],
        ];
    }
}
