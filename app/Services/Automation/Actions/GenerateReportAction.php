<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\User;
use App\Services\Automation\Actions\Contracts\ActionInterface;
use App\Services\Report\ReportService;

class GenerateReportAction implements ActionInterface
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $reportType = $actionConfig['report_type'] ?? 'reviews';
        $locationId = $contextData['location_id'] ?? null;
        $userId = $actionConfig['user_id'] ?? $workflow->created_by;

        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        $location = null;
        if ($locationId) {
            $location = Location::find($locationId);
            if (!$location) {
                throw new \RuntimeException("Location not found: {$locationId}");
            }
        }

        // Prepare report data
        $reportData = [
            'type' => $reportType,
            'format' => $actionConfig['format'] ?? 'pdf',
            'date_from' => $actionConfig['date_from'] ?? now()->subDays(30)->format('Y-m-d'),
            'date_to' => $actionConfig['date_to'] ?? now()->format('Y-m-d'),
            'location_id' => $locationId,
            'email_recipients' => $actionConfig['email_recipients'] ?? [],
        ];

        // Generate the report
        $report = $this->reportService->generate(
            $workflow->tenant,
            $user,
            $reportData
        );

        return [
            'success' => true,
            'report_id' => $report->id,
            'report_type' => $reportType,
            'format' => $report->format,
            'status' => $report->status,
            'location_id' => $locationId,
            'email_sent' => !empty($actionConfig['email_recipients']),
        ];
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        $validTypes = ['reviews', 'sentiment', 'summary', 'trends', 'location_comparison'];
        if (isset($actionConfig['report_type']) && !in_array($actionConfig['report_type'], $validTypes)) {
            $errors[] = 'report_type must be one of: ' . implode(', ', $validTypes);
        }

        $validFormats = ['pdf', 'excel', 'csv'];
        if (isset($actionConfig['format']) && !in_array($actionConfig['format'], $validFormats)) {
            $errors[] = 'format must be one of: ' . implode(', ', $validFormats);
        }

        if (isset($actionConfig['user_id']) && !User::find($actionConfig['user_id'])) {
            $errors[] = 'user_id must reference an existing user';
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'Generate Report';
    }

    public function getDescription(): string
    {
        return 'Generate and optionally email a report';
    }

    public function getConfigSchema(): array
    {
        return [
            'report_type' => [
                'type' => 'string',
                'required' => false,
                'default' => 'reviews',
                'enum' => ['reviews', 'sentiment', 'summary', 'trends', 'location_comparison'],
                'description' => 'Type of report to generate',
            ],
            'format' => [
                'type' => 'string',
                'required' => false,
                'default' => 'pdf',
                'enum' => ['pdf', 'excel', 'csv'],
                'description' => 'Report format',
            ],
            'date_from' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Start date for report data (YYYY-MM-DD)',
            ],
            'date_to' => [
                'type' => 'string',
                'required' => false,
                'description' => 'End date for report data (YYYY-MM-DD)',
            ],
            'user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'User ID to attribute the report to',
            ],
            'email_recipients' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Email addresses to send the report to',
            ],
        ];
    }
}