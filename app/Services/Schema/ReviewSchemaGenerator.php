<?php

namespace App\Services\Schema;

use App\Models\Review;

class ReviewSchemaGenerator extends SchemaGenerator
{
    protected string $type = 'Review';

    protected Review $review;

    public function __construct(Review $review)
    {
        $this->review = $review;
    }

    protected function generateData(): array
    {
        return $this->cleanArray([
            'author' => $this->generateAuthor(),
            'reviewRating' => $this->generateRating(),
            'reviewBody' => $this->review->content,
            'datePublished' => $this->formatDate($this->review->created_at),
            'name' => $this->review->content ? substr($this->review->content, 0, 100) : 'Review',
            'publisher' => $this->generatePublisher(),
        ]);
    }

    protected function getRequiredFields(): array
    {
        return [
            'author',
            'reviewRating',
            'datePublished',
        ];
    }

    private function generateAuthor(): array
    {
        $authorName = $this->review->author_name ?? 'Anonymous';

        return $this->createPersonSchema($authorName);
    }

    private function generateRating(): array
    {
        return $this->createRatingSchema(
            $this->review->rating,
            1,
            5
        );
    }

    private function generatePublisher(): array
    {
        return $this->createOrganizationSchema(
            config('app.name', 'Clarity-SEO'),
            url('/')
        );
    }
}
