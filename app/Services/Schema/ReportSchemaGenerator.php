<?php

namespace App\Services\Schema;

use App\Models\Report;

class ReportSchemaGenerator extends SchemaGenerator
{
    protected string $type = 'Report';

    protected Report $report;

    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    protected function generateData(): array
    {
        return $this->cleanArray([
            'name' => $this->report->name ?? $this->report->type,
            'datePublished' => $this->formatDate($this->report->created_at),
            'author' => $this->generateAuthor(),
            'hasPart' => $this->generateParts(),
        ]);
    }

    protected function getRequiredFields(): array
    {
        return [
            'name',
            'datePublished',
        ];
    }

    private function generateAuthor(): array
    {
        return $this->createOrganizationSchema(
            config('app.name', 'Clarity-SEO'),
            url('/')
        );
    }

    private function generateParts(): ?array
    {
        $parts = [];

        // Add period info
        if ($this->report->date_from) {
            $parts[] = [
                '@type' => 'PropertyValue',
                'name' => 'Date From',
                'value' => $this->report->date_from->toIso8601String(),
            ];
        }

        if ($this->report->date_to) {
            $parts[] = [
                '@type' => 'PropertyValue',
                'name' => 'Date To',
                'value' => $this->report->date_to->toIso8601String(),
            ];
        }

        // Add type as part
        if ($this->report->type) {
            $parts[] = [
                '@type' => 'PropertyValue',
                'name' => 'Report Type',
                'value' => $this->report->type,
            ];
        }

        return empty($parts) ? null : $parts;
    }
}
