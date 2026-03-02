<?php

namespace App\Services\Schema;

use Illuminate\Support\Arr;

class SchemaValidator
{
    private array $errors = [];

    /**
     * Validate schema structure
     */
    public function validate(array $schema): bool
    {
        $this->errors = [];

        // Check context
        if (!isset($schema['@context']) || $schema['@context'] !== 'https://schema.org') {
            $this->errors[] = 'Invalid or missing @context';
        }

        // Check type
        if (!isset($schema['@type'])) {
            $this->errors[] = 'Missing @type';
        }

        // Check for required Review fields
        if ($schema['@type'] === 'Review') {
            $this->validateReview($schema);
        }

        // Check for required LocalBusiness fields
        if ($schema['@type'] === 'LocalBusiness') {
            $this->validateLocalBusiness($schema);
        }

        // Check for required Report fields
        if ($schema['@type'] === 'Report') {
            $this->validateReport($schema);
        }

        return empty($this->errors);
    }

    /**
     * Validate Review schema
     */
    private function validateReview(array $schema): void
    {
        if (!isset($schema['reviewRating']) || !is_array($schema['reviewRating'])) {
            $this->errors[] = 'Review: Missing or invalid reviewRating';
        } elseif (!isset($schema['reviewRating']['ratingValue'])) {
            $this->errors[] = 'Review: Missing ratingValue in reviewRating';
        }

        if (!isset($schema['author'])) {
            $this->errors[] = 'Review: Missing author';
        }

        if (!isset($schema['datePublished'])) {
            $this->errors[] = 'Review: Missing datePublished';
        }

        if (!isset($schema['reviewBody'])) {
            $this->errors[] = 'Review: Missing reviewBody';
        }
    }

    /**
     * Validate LocalBusiness schema
     */
    private function validateLocalBusiness(array $schema): void
    {
        if (!isset($schema['name'])) {
            $this->errors[] = 'LocalBusiness: Missing name';
        }

        if (!isset($schema['address'])) {
            $this->errors[] = 'LocalBusiness: Missing address';
        }

        if (isset($schema['aggregateRating'])) {
            if (!isset($schema['aggregateRating']['ratingValue'])) {
                $this->errors[] = 'LocalBusiness: Missing ratingValue in aggregateRating';
            }
            if (!isset($schema['aggregateRating']['reviewCount'])) {
                $this->errors[] = 'LocalBusiness: Missing reviewCount in aggregateRating';
            }
        }
    }

    /**
     * Validate Report schema
     */
    private function validateReport(array $schema): void
    {
        if (!isset($schema['name'])) {
            $this->errors[] = 'Report: Missing name';
        }

        if (!isset($schema['datePublished'])) {
            $this->errors[] = 'Report: Missing datePublished';
        }
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error message
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
