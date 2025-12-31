<?php

declare(strict_types=1);

namespace App\Http\Resources\Sentiment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SentimentStatsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'total_analyzed' => $this->resource['total_analyzed'],
            'sentiment_distribution' => $this->resource['sentiment_distribution'],
            'average_sentiment_score' => $this->resource['average_sentiment_score'],
            'sentiment_percentages' => $this->resource['sentiment_percentages'],
        ];
    }
}
