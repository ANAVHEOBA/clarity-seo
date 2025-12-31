<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportTemplateRequest;
use App\Http\Requests\Report\UpdateReportTemplateRequest;
use App\Http\Resources\Report\ReportTemplateResource;
use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportTemplateController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $templates = $this->reportService->listTemplatesForTenant($tenant);

        return ReportTemplateResource::collection($templates);
    }

    public function store(StoreReportTemplateRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $template = $this->reportService->createTemplate(
            $tenant,
            $request->user(),
            $request->validated()
        );

        return (new ReportTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant, ReportTemplate $reportTemplate): ReportTemplateResource|JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($reportTemplate->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        return new ReportTemplateResource($reportTemplate);
    }

    public function update(UpdateReportTemplateRequest $request, Tenant $tenant, ReportTemplate $reportTemplate): ReportTemplateResource|JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($reportTemplate->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $template = $this->reportService->updateTemplate(
            $reportTemplate,
            $request->validated()
        );

        return new ReportTemplateResource($template);
    }

    public function destroy(Tenant $tenant, ReportTemplate $reportTemplate): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($reportTemplate->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $this->reportService->deleteTemplate($reportTemplate);

        return response()->json(null, 204);
    }
}
