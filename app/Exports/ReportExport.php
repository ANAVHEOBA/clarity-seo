<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class ReportExport implements FromArray, WithHeadings, WithMultipleSheets, WithTitle
{
    public function __construct(
        private array $data,
        private string $type,
        private bool $multiSheet = false
    ) {}

    public function sheets(): array
    {
        if (! $this->multiSheet) {
            return [$this];
        }

        return match ($this->type) {
            'summary' => [
                new ReportSheetExport($this->buildSummarySheet(), 'Summary', $this->getSummaryHeadings()),
                new ReportSheetExport($this->buildRatingSheet(), 'Ratings', ['Rating', 'Count']),
                new ReportSheetExport($this->buildPlatformSheet(), 'By Platform', ['Platform', 'Count', 'Average Rating']),
            ],
            default => [$this],
        };
    }

    public function array(): array
    {
        return match ($this->type) {
            'reviews', 'reviews_detailed' => $this->buildReviewsArray(),
            'sentiment' => $this->buildSentimentArray(),
            'summary' => $this->buildSummaryArray(),
            'trends' => $this->buildTrendsArray(),
            'location_comparison' => $this->buildLocationComparisonArray(),
            default => $this->buildReviewsArray(),
        };
    }

    public function headings(): array
    {
        return match ($this->type) {
            'reviews' => ['ID', 'Author', 'Rating', 'Content', 'Platform', 'Location', 'Published At', 'Has Response'],
            'reviews_detailed' => [
                'ID', 'Author', 'Rating', 'Content', 'Platform', 'Published At',
                'Location', 'Address', 'Has Response', 'Response Content', 'Response Date',
                'Sentiment', 'Sentiment Score',
            ],
            'sentiment' => ['ID', 'Author', 'Content', 'Sentiment', 'Score', 'Key Phrases', 'Published At'],
            'summary' => ['Metric', 'Value'],
            'trends' => ['Period', 'Review Count', 'Average Rating'],
            'location_comparison' => ['Location', 'Address', 'Total Reviews', 'Average Rating', 'Response Rate'],
            default => ['ID', 'Author', 'Rating', 'Content', 'Platform', 'Published At'],
        };
    }

    public function title(): string
    {
        return ucfirst(str_replace('_', ' ', $this->type));
    }

    private function buildReviewsArray(): array
    {
        $reviews = $this->data['reviews'] ?? [];

        if ($this->type === 'reviews_detailed') {
            return array_map(fn ($review) => [
                $review['id'] ?? '',
                $review['author_name'] ?? $review['author'] ?? '',
                $review['rating'] ?? '',
                $this->sanitizeContent($review['content'] ?? ''),
                $review['platform'] ?? '',
                $review['published_at'] ?? '',
                $review['location_name'] ?? $review['location'] ?? '',
                $review['location_address'] ?? '',
                $review['has_response'] ?? '',
                $this->sanitizeContent($review['response_content'] ?? ''),
                $review['response_date'] ?? '',
                $review['sentiment'] ?? '',
                $review['sentiment_score'] ?? '',
            ], $reviews);
        }

        return array_map(fn ($review) => [
            $review['id'] ?? '',
            $review['author'] ?? $review['author_name'] ?? '',
            $review['rating'] ?? '',
            $this->sanitizeContent($review['content'] ?? ''),
            $review['platform'] ?? '',
            $review['location'] ?? '',
            $review['published_at'] ?? '',
            isset($review['has_response']) ? ($review['has_response'] ? 'Yes' : 'No') : '',
        ], $reviews);
    }

    private function buildSentimentArray(): array
    {
        $reviews = $this->data['reviews'] ?? [];

        return array_map(fn ($review) => [
            $review['id'] ?? '',
            $review['author'] ?? '',
            $this->sanitizeContent($review['content'] ?? ''),
            $review['sentiment'] ?? '',
            $review['sentiment_score'] ?? '',
            is_array($review['key_phrases'] ?? null) ? implode(', ', $review['key_phrases']) : '',
            $review['published_at'] ?? '',
        ], $reviews);
    }

    private function buildSummaryArray(): array
    {
        $overview = $this->data['overview'] ?? $this->data['summary'] ?? [];

        return [
            ['Total Reviews', $overview['total_reviews'] ?? 0],
            ['Average Rating', $overview['average_rating'] ?? 0],
            ['Total Locations', $overview['total_locations'] ?? 0],
            ['Response Rate', ($overview['response_rate'] ?? 0).'%'],
        ];
    }

    private function buildTrendsArray(): array
    {
        $trends = $this->data['trends_by_month'] ?? $this->data['trends_by_week'] ?? [];

        return array_map(fn ($period, $data) => [
            $period,
            $data['count'] ?? 0,
            $data['average_rating'] ?? 0,
        ], array_keys($trends), array_values($trends));
    }

    private function buildLocationComparisonArray(): array
    {
        $locations = $this->data['locations'] ?? [];

        return array_map(fn ($location) => [
            $location['name'] ?? '',
            $location['address'] ?? '',
            $location['total_reviews'] ?? 0,
            $location['average_rating'] ?? 0,
            ($location['response_rate'] ?? 0).'%',
        ], $locations);
    }

    private function buildSummarySheet(): array
    {
        $overview = $this->data['overview'] ?? [];

        return [
            [$overview['total_reviews'] ?? 0],
            [$overview['average_rating'] ?? 0],
            [$overview['total_locations'] ?? 0],
            [($overview['response_rate'] ?? 0).'%'],
        ];
    }

    private function buildRatingSheet(): array
    {
        $distribution = $this->data['rating_distribution'] ?? [];

        return array_map(fn ($rating, $count) => [$rating, $count], array_keys($distribution), array_values($distribution));
    }

    private function buildPlatformSheet(): array
    {
        $byPlatform = $this->data['by_platform'] ?? [];

        return array_map(fn ($platform, $data) => [
            $platform,
            $data['count'] ?? 0,
            $data['average_rating'] ?? 0,
        ], array_keys($byPlatform), array_values($byPlatform));
    }

    private function getSummaryHeadings(): array
    {
        return ['Total Reviews', 'Average Rating', 'Total Locations', 'Response Rate'];
    }

    private function sanitizeContent(?string $content): string
    {
        if ($content === null) {
            return '';
        }

        return strip_tags(html_entity_decode($content));
    }
}
