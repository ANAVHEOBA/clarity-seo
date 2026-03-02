<?php

namespace App\Helpers;

use App\Services\Schema\SchemaGenerator;

class SchemaHelper
{
    /**
     * Render JSON-LD script tag
     */
    public static function toJsonLd(SchemaGenerator $generator): string
    {
        return sprintf(
            '<script type="application/ld+json">%s</script>',
            $generator->toJson()
        );
    }

    /**
     * Render multiple JSON-LD schemas
     */
    public static function toMultipleJsonLd(array $generators): string
    {
        $schemas = array_map(function (SchemaGenerator $generator) {
            return $generator->generate();
        }, $generators);

        $json = json_encode($schemas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf(
            '<script type="application/ld+json">%s</script>',
            $json
        );
    }

    /**
     * Validate all generators
     */
    public static function validateAll(array $generators): bool
    {
        foreach ($generators as $generator) {
            if (!$generator->validate()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract JSON-LD from HTML content (for testing)
     */
    public static function extractJsonLd(string $html): ?array
    {
        if (!preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $matches)) {
            return null;
        }

        $json = $matches[1];

        return json_decode($json, true);
    }

    /**
     * Extract all JSON-LD scripts from HTML (for testing)
     */
    public static function extractAllJsonLd(string $html): array
    {
        $results = [];

        if (!preg_match_all('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $matches)) {
            return $results;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                // Check if it's an array of schemas
                if (isset($decoded[0]['@type'])) {
                    $results = array_merge($results, $decoded);
                } else {
                    $results[] = $decoded;
                }
            }
        }

        return $results;
    }
}
