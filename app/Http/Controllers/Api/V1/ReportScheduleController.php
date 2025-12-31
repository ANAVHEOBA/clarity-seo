<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportScheduleRequest;
use App\Http\Requests\Report\UpdateReportScheduleRequest;
use App\Http\Resources\Report\ReportScheduleResource;
use App\Models\ReportSchedule;
use App\Models\Tenant;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportScheduleController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $schedules = $this->reportService->listSchedulesForTenant($tenant);

        return ReportScheduleResource::collection($schedules);
    }

    public function store(StoreReportScheduleRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $schedule = $this->reportService->createSchedule(
            $tenant,
            $request->user(),
            $request->validated()
        );

        return (new ReportScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant, ReportSchedule $reportSchedule): ReportScheduleResource|JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($reportSchedule->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Schedule not found'], 404);
        }

        return new ReportScheduleResource($reportSchedule);
    }

    public function update(UpdateReportScheduleRequest $request, Tenant $tenant, ReportSchedule $reportSchedule): ReportScheduleResource|JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($reportSchedule->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Schedule not found'], 404);
        }

        $schedule = $this->reportService->updateSchedule(
            $reportSchedule,
            $request->validated()
        );

        return new ReportScheduleResource($schedule);
    }

    public function toggle(Tenant $tenant, ReportSchedule $reportSchedule): ReportScheduleResource|JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($reportSchedule->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Schedule not found'], 404);
        }

        $schedule = $this->reportService->toggleSchedule($reportSchedule);

        return new ReportScheduleResource($schedule);
    }

    public function destroy(Tenant $tenant, ReportSchedule $reportSchedule): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($reportSchedule->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Schedule not found'], 404);
        }

        $this->reportService->deleteSchedule($reportSchedule);

        return response()->json(null, 204);
    }
}
