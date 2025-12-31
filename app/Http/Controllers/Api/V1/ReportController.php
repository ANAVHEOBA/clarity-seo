<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportRequest;
use App\Http\Resources\Report\ReportResource;
use App\Models\Report;
use App\Models\Tenant;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $reports = $this->reportService->listForTenant($tenant, $request->all());

        return ReportResource::collection($reports);
    }

    public function store(StoreReportRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $report = $this->reportService->generate(
            $tenant,
            $request->user(),
            $request->validated()
        );

        return (new ReportResource($report))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant, Report $report): ReportResource|JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($report->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        return new ReportResource($report);
    }

    public function status(Tenant $tenant, Report $report): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($report->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        return response()->json([
            'data' => $this->reportService->getReportStatus($report),
        ]);
    }

    public function download(Tenant $tenant, Report $report): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($report->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        if (! $report->isCompleted() || ! $report->file_path) {
            return response()->json(['message' => 'Report not ready for download'], 400);
        }

        if (! Storage::disk('local')->exists($report->file_path)) {
            return response()->json(['message' => 'Report file not found'], 404);
        }

        $mimeType = match ($report->format) {
            'pdf' => 'application/pdf',
            'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->download(
            $report->file_path,
            $report->file_name,
            ['Content-Type' => $mimeType]
        );
    }

    public function destroy(Tenant $tenant, Report $report): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($report->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        $this->reportService->delete($report);

        return response()->json(null, 204);
    }
}
