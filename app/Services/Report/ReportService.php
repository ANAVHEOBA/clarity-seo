<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Exports\ReportExport;
use App\Mail\ReportReady;
use App\Models\Location;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ReportService
{
    public const LARGE_DATASET_THRESHOLD = 100;

    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = Report::query()
            ->where('tenant_id', $tenant->id)
            ->with(['user', 'location']);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['format'])) {
            $query->where('format', $filters['format']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    public function findForTenant(Tenant $tenant, int $reportId): ?Report
    {
        return Report::query()
            ->where('tenant_id', $tenant->id)
            ->with(['user', 'location', 'template', 'schedule'])
            ->find($reportId);
    }

    public function generate(Tenant $tenant, User $user, array $data): Report
    {
        // If using a template, merge template settings into data
        if (! empty($data['template_id'])) {
            $template = ReportTemplate::find($data['template_id']);
            if ($template) {
                $data['type'] = $data['type'] ?? $template->type;
                $data['branding'] = $data['branding'] ?? $template->branding;
                $data['filters'] = $data['filters'] ?? $template->filters;
            }
        }

        $dateRange = $this->resolveDateRange($data);
        $type = $data['type'] ?? 'reviews';
        $format = $data['format'];

        $report = Report::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'location_id' => $data['location_id'] ?? null,
            'report_template_id' => $data['template_id'] ?? null,
            'name' => $data['name'] ?? $this->generateReportName($type, $format),
            'type' => $type,
            'format' => $format,
            'status' => 'pending',
            'date_from' => $dateRange['from'],
            'date_to' => $dateRange['to'],
            'period' => $data['period'] ?? null,
            'location_ids' => $data['location_ids'] ?? null,
            'filters' => $data['filters'] ?? null,
            'branding' => $data['branding'] ?? null,
            'options' => $this->buildOptions($data),
        ]);

        $reviewCount = $this->getReviewCount($tenant, $report);

        if ($reviewCount > self::LARGE_DATASET_THRESHOLD) {
            $report->update(['status' => 'processing']);
            dispatch(fn () => $this->processReport($report, $tenant, $data));
        } else {
            $this->processReport($report, $tenant, $data);
        }

        return $report->fresh();
    }

    public function processReport(Report $report, Tenant $tenant, array $data): void
    {
        try {
            $report->markAsProcessing();

            $reportData = $this->gatherReportData($tenant, $report);

            $filePath = match ($report->format) {
                'pdf' => $this->generatePdf($report, $reportData),
                'excel' => $this->generateExcel($report, $reportData),
                'csv' => $this->generateCsv($report, $reportData),
                default => throw new \InvalidArgumentException("Unsupported format: {$report->format}"),
            };

            $fileSize = Storage::disk('local')->size($filePath);
            $fileName = basename($filePath);

            $report->markAsCompleted($filePath, $fileName, $fileSize);

            if (! empty($data['send_email']) && ! empty($data['recipients'])) {
                $this->sendReportEmails($report, $data['recipients'], $data);
            }
        } catch (\Exception $e) {
            $report->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    public function delete(Report $report): void
    {
        if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
            Storage::disk('local')->delete($report->file_path);
        }

        $report->delete();
    }

    public function getDownloadUrl(Report $report): ?string
    {
        if (! $report->isCompleted() || ! $report->file_path) {
            return null;
        }

        return route('api.v1.reports.download', [
            'tenant' => $report->tenant_id,
            'report' => $report->id,
        ]);
    }

    public function getReportStatus(Report $report): array
    {
        return [
            'status' => $report->status,
            'progress' => $report->progress,
        ];
    }

    // Report Schedules

    public function listSchedulesForTenant(Tenant $tenant): Collection
    {
        return ReportSchedule::query()
            ->where('tenant_id', $tenant->id)
            ->with(['user', 'template'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createSchedule(Tenant $tenant, User $user, array $data): ReportSchedule
    {
        return ReportSchedule::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'report_template_id' => $data['template_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'format' => $data['format'] ?? 'pdf',
            'frequency' => $data['frequency'],
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'time_of_day' => $data['time'] ?? '09:00:00',
            'timezone' => $data['timezone'] ?? 'UTC',
            'period' => $data['period'] ?? 'last_30_days',
            'location_ids' => $data['location_ids'] ?? null,
            'filters' => $data['filters'] ?? null,
            'branding' => $data['branding'] ?? null,
            'options' => $data['options'] ?? null,
            'recipients' => $data['recipients'] ?? [],
            'is_active' => true,
            'next_run_at' => $this->calculateNextRun($data),
        ]);
    }

    public function updateSchedule(ReportSchedule $schedule, array $data): ReportSchedule
    {
        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? null,
            'format' => $data['format'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'time_of_day' => $data['time'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'period' => $data['period'] ?? null,
            'location_ids' => $data['location_ids'] ?? null,
            'filters' => $data['filters'] ?? null,
            'branding' => $data['branding'] ?? null,
            'options' => $data['options'] ?? null,
            'recipients' => $data['recipients'] ?? null,
        ], fn ($value) => $value !== null);

        $schedule->update($updateData);

        return $schedule->fresh();
    }

    public function toggleSchedule(ReportSchedule $schedule): ReportSchedule
    {
        $schedule->update(['is_active' => ! $schedule->is_active]);

        return $schedule->fresh();
    }

    public function deleteSchedule(ReportSchedule $schedule): void
    {
        $schedule->delete();
    }

    // Report Templates

    public function listTemplatesForTenant(Tenant $tenant): Collection
    {
        return ReportTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createTemplate(Tenant $tenant, User $user, array $data): ReportTemplate
    {
        if (! empty($data['is_default'])) {
            ReportTemplate::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', $data['type'])
                ->update(['is_default' => false]);
        }

        return ReportTemplate::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'format' => $data['format'] ?? 'pdf',
            'sections' => $data['sections'] ?? null,
            'branding' => $data['branding'] ?? null,
            'filters' => $data['filters'] ?? null,
            'options' => $data['options'] ?? null,
            'is_default' => $data['is_default'] ?? false,
        ]);
    }

    public function updateTemplate(ReportTemplate $template, array $data): ReportTemplate
    {
        if (! empty($data['is_default'])) {
            ReportTemplate::query()
                ->where('tenant_id', $template->tenant_id)
                ->where('type', $template->type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        }

        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? null,
            'format' => $data['format'] ?? null,
            'sections' => $data['sections'] ?? null,
            'branding' => $data['branding'] ?? null,
            'filters' => $data['filters'] ?? null,
            'options' => $data['options'] ?? null,
            'is_default' => $data['is_default'] ?? null,
        ], fn ($value) => $value !== null);

        $template->update($updateData);

        return $template->fresh();
    }

    public function deleteTemplate(ReportTemplate $template): void
    {
        $template->delete();
    }

    // Private helper methods

    private function resolveDateRange(array $data): array
    {
        if (isset($data['date_from']) && isset($data['date_to'])) {
            return [
                'from' => Carbon::parse($data['date_from']),
                'to' => Carbon::parse($data['date_to']),
            ];
        }

        $period = $data['period'] ?? 'last_30_days';

        return match ($period) {
            'last_7_days' => [
                'from' => now()->subDays(7),
                'to' => now(),
            ],
            'last_30_days' => [
                'from' => now()->subDays(30),
                'to' => now(),
            ],
            'last_quarter' => [
                'from' => now()->subMonths(3),
                'to' => now(),
            ],
            'year_to_date' => [
                'from' => now()->startOfYear(),
                'to' => now(),
            ],
            default => [
                'from' => now()->subDays(30),
                'to' => now(),
            ],
        };
    }

    private function generateReportName(string $type, string $format): string
    {
        $typeLabel = str_replace('_', ' ', $type);

        return ucwords($typeLabel).' Report - '.now()->format('Y-m-d');
    }

    private function buildOptions(array $data): array
    {
        return [
            'include_charts' => $data['include_charts'] ?? false,
            'include_headers' => $data['include_headers'] ?? true,
            'multi_sheet' => $data['multi_sheet'] ?? false,
        ];
    }

    private function getReviewCount(Tenant $tenant, Report $report): int
    {
        $query = $this->buildReviewQuery($tenant, $report);

        return $query->count();
    }

    private function buildReviewQuery(Tenant $tenant, Report $report): Builder
    {
        if ($report->location_id) {
            $locationIds = [$report->location_id];
        } elseif ($report->location_ids) {
            $locationIds = $report->location_ids;
        } else {
            $locationIds = $tenant->locations()->pluck('id')->toArray();
        }

        $query = Review::query()->whereIn('location_id', $locationIds);

        if ($report->date_from) {
            $query->whereDate('published_at', '>=', $report->date_from);
        }

        if ($report->date_to) {
            $query->whereDate('published_at', '<=', $report->date_to);
        }

        return $query;
    }

    private function gatherReportData(Tenant $tenant, Report $report): array
    {
        $reviews = $this->buildReviewQuery($tenant, $report)
            ->with(['location', 'response', 'sentiment'])
            ->orderBy('published_at', 'desc')
            ->get();

        $locations = $report->location_ids
            ? Location::whereIn('id', $report->location_ids)->get()
            : ($report->location_id
                ? Location::where('id', $report->location_id)->get()
                : $tenant->locations);

        return match ($report->type) {
            'reviews' => $this->buildReviewsReportData($reviews, $locations, $report),
            'sentiment' => $this->buildSentimentReportData($reviews, $locations, $report),
            'summary' => $this->buildSummaryReportData($reviews, $locations, $report),
            'trends' => $this->buildTrendsReportData($reviews, $locations, $report),
            'location_comparison' => $this->buildLocationComparisonData($reviews, $locations, $report),
            'reviews_detailed' => $this->buildDetailedReviewsData($reviews, $locations, $report),
            default => $this->buildReviewsReportData($reviews, $locations, $report),
        };
    }

    private function buildReviewsReportData(Collection $reviews, $locations, Report $report): array
    {
        return [
            'title' => $report->name ?? 'Reviews Report',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'summary' => [
                'total_reviews' => $reviews->count(),
                'average_rating' => round($reviews->avg('rating') ?? 0, 1),
                'rating_distribution' => $reviews->groupBy('rating')->map->count()->toArray(),
            ],
            'reviews' => $reviews->map(fn ($review) => [
                'id' => $review->id,
                'author' => $review->author_name,
                'rating' => $review->rating,
                'content' => $review->content,
                'platform' => $review->platform,
                'published_at' => $review->published_at?->format('Y-m-d H:i'),
                'location' => $review->location?->name,
                'has_response' => $review->response !== null,
            ])->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function buildSentimentReportData(Collection $reviews, $locations, Report $report): array
    {
        $reviewsWithSentiment = $reviews->filter(fn ($r) => $r->sentiment !== null);

        $sentimentDistribution = $reviewsWithSentiment
            ->groupBy(fn ($r) => $r->sentiment->sentiment)
            ->map->count();

        return [
            'title' => $report->name ?? 'Sentiment Analysis Report',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'summary' => [
                'total_analyzed' => $reviewsWithSentiment->count(),
                'average_sentiment_score' => round($reviewsWithSentiment->avg(fn ($r) => $r->sentiment->sentiment_score) ?? 0, 2),
                'sentiment_distribution' => $sentimentDistribution->toArray(),
            ],
            'reviews' => $reviewsWithSentiment->map(fn ($review) => [
                'id' => $review->id,
                'author' => $review->author_name,
                'content' => $review->content,
                'sentiment' => $review->sentiment->sentiment,
                'sentiment_score' => $review->sentiment->sentiment_score,
                'key_phrases' => $review->sentiment->key_phrases,
                'published_at' => $review->published_at?->format('Y-m-d'),
            ])->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function buildSummaryReportData(Collection $reviews, $locations, Report $report): array
    {
        $byPlatform = $reviews->groupBy('platform')->map(fn ($group) => [
            'count' => $group->count(),
            'average_rating' => round($group->avg('rating') ?? 0, 1),
        ]);

        $byLocation = $reviews->groupBy('location_id')->map(fn ($group) => [
            'count' => $group->count(),
            'average_rating' => round($group->avg('rating') ?? 0, 1),
            'location_name' => $group->first()?->location?->name,
        ]);

        return [
            'title' => $report->name ?? 'Summary Report',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'overview' => [
                'total_reviews' => $reviews->count(),
                'average_rating' => round($reviews->avg('rating') ?? 0, 1),
                'total_locations' => $locations->count(),
                'response_rate' => $reviews->count() > 0
                    ? round($reviews->filter(fn ($r) => $r->response !== null)->count() / $reviews->count() * 100, 1)
                    : 0,
            ],
            'rating_distribution' => $reviews->groupBy('rating')->map->count()->toArray(),
            'by_platform' => $byPlatform->toArray(),
            'by_location' => $byLocation->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function buildTrendsReportData(Collection $reviews, $locations, Report $report): array
    {
        $byMonth = $reviews->groupBy(fn ($r) => $r->published_at?->format('Y-m'))
            ->map(fn ($group) => [
                'count' => $group->count(),
                'average_rating' => round($group->avg('rating') ?? 0, 1),
            ])
            ->sortKeys();

        $byWeek = $reviews->groupBy(fn ($r) => $r->published_at?->startOfWeek()->format('Y-m-d'))
            ->map(fn ($group) => [
                'count' => $group->count(),
                'average_rating' => round($group->avg('rating') ?? 0, 1),
            ])
            ->sortKeys();

        return [
            'title' => $report->name ?? 'Trends Report',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'summary' => [
                'total_reviews' => $reviews->count(),
                'average_rating' => round($reviews->avg('rating') ?? 0, 1),
            ],
            'trends_by_month' => $byMonth->toArray(),
            'trends_by_week' => $byWeek->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function buildLocationComparisonData(Collection $reviews, $locations, Report $report): array
    {
        $byLocation = $locations->map(function ($location) use ($reviews) {
            $locationReviews = $reviews->where('location_id', $location->id);

            return [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'total_reviews' => $locationReviews->count(),
                'average_rating' => round($locationReviews->avg('rating') ?? 0, 1),
                'rating_distribution' => $locationReviews->groupBy('rating')->map->count()->toArray(),
                'response_rate' => $locationReviews->count() > 0
                    ? round($locationReviews->filter(fn ($r) => $r->response !== null)->count() / $locationReviews->count() * 100, 1)
                    : 0,
            ];
        });

        return [
            'title' => $report->name ?? 'Location Comparison Report',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'locations' => $byLocation->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function buildDetailedReviewsData(Collection $reviews, $locations, Report $report): array
    {
        return [
            'title' => $report->name ?? 'Detailed Reviews Export',
            'generated_at' => now()->toDateTimeString(),
            'date_range' => [
                'from' => $report->date_from?->format('Y-m-d'),
                'to' => $report->date_to?->format('Y-m-d'),
            ],
            'reviews' => $reviews->map(fn ($review) => [
                'id' => $review->id,
                'author_name' => $review->author_name,
                'rating' => $review->rating,
                'content' => $review->content,
                'platform' => $review->platform,
                'published_at' => $review->published_at?->format('Y-m-d H:i:s'),
                'location_name' => $review->location?->name,
                'location_address' => $review->location?->address,
                'has_response' => $review->response !== null ? 'Yes' : 'No',
                'response_content' => $review->response?->content,
                'response_date' => $review->response?->created_at?->format('Y-m-d H:i:s'),
                'sentiment' => $review->sentiment?->sentiment,
                'sentiment_score' => $review->sentiment?->sentiment_score,
            ])->toArray(),
            'branding' => $report->branding,
        ];
    }

    private function generatePdf(Report $report, array $data): string
    {
        $view = match ($report->type) {
            'sentiment' => 'reports.sentiment',
            'summary' => 'reports.summary',
            'trends' => 'reports.trends',
            'location_comparison' => 'reports.location-comparison',
            default => 'reports.reviews',
        };

        $pdf = Pdf::loadView($view, ['data' => $data]);

        $fileName = Str::slug($report->name ?? 'report').'-'.now()->format('Y-m-d-His').'.pdf';
        $filePath = 'reports/'.$fileName;

        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    private function generateExcel(Report $report, array $data): string
    {
        $fileName = Str::slug($report->name ?? 'report').'-'.now()->format('Y-m-d-His').'.xlsx';
        $filePath = 'reports/'.$fileName;

        $export = new ReportExport($data, $report->type, $report->options['multi_sheet'] ?? false);

        Excel::store($export, $filePath, 'local');

        return $filePath;
    }

    private function generateCsv(Report $report, array $data): string
    {
        $fileName = Str::slug($report->name ?? 'report').'-'.now()->format('Y-m-d-His').'.csv';
        $filePath = 'reports/'.$fileName;

        $export = new ReportExport($data, $report->type, false);

        Excel::store($export, $filePath, 'local', \Maatwebsite\Excel\Excel::CSV);

        return $filePath;
    }

    private function sendReportEmails(Report $report, array $recipients, array $data): void
    {
        foreach ($recipients as $email) {
            Mail::to($email)->queue(new ReportReady(
                $report,
                $data['email_subject'] ?? null,
                $data['email_message'] ?? null
            ));
        }
    }

    private function calculateNextRun(array $data): Carbon
    {
        $timezone = $data['timezone'] ?? 'UTC';
        $time = $data['time'] ?? '09:00';
        $timeParts = explode(':', $time);
        $hour = (int) $timeParts[0];
        $minute = (int) ($timeParts[1] ?? 0);

        $now = now()->setTimezone($timezone);

        return match ($data['frequency']) {
            'daily' => $now->copy()->setTime($hour, $minute)->addDay(),
            'weekly' => $now->copy()->next($data['day_of_week'] ?? 'monday')->setTime($hour, $minute),
            'monthly' => $now->copy()->setDay($data['day_of_month'] ?? 1)->setTime($hour, $minute)->addMonth(),
            default => $now->copy()->addDay(),
        };
    }
}
