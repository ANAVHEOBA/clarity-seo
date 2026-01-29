<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReportSchedule;
use App\Services\Report\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledReportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-scheduled-reports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send scheduled reports';

    public function __construct(
        protected ReportService $reportService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for scheduled reports...');

        $dueSchedules = ReportSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->with(['tenant', 'user']) // Eager load relationships
            ->get();

        if ($dueSchedules->isEmpty()) {
            $this->info('No reports due at this time.');
            return 0;
        }

        $this->info("Found {$dueSchedules->count()} reports to generate.");

        foreach ($dueSchedules as $schedule) {
            try {
                $this->info("Processing schedule: {$schedule->name} (ID: {$schedule->id})");

                // Map schedule data to generation data
                $reportData = [
                    'template_id' => $schedule->report_template_id,
                    'name' => $schedule->name, // Or dynamic name with date?
                    'type' => $schedule->type,
                    'format' => $schedule->format,
                    'period' => $schedule->period,
                    'location_ids' => $schedule->location_ids,
                    'filters' => $schedule->filters ?? [],
                    'branding' => $schedule->branding ?? [],
                    'options' => $schedule->options ?? [],
                    'send_email' => !empty($schedule->recipients),
                    'recipients' => $schedule->recipients ?? [],
                    // 'date_from' / 'date_to' will be resolved by Service based on 'period'
                ];

                // Generate and send the report
                $this->reportService->generate(
                    $schedule->tenant,
                    $schedule->user,
                    $reportData
                );

                // Update next run time
                $schedule->markAsRun();

                $this->info("  Successfully generated and scheduled for sending.");

            } catch (\Exception $e) {
                $this->error("  Failed to process schedule {$schedule->id}: " . $e->getMessage());
                Log::error('Scheduled Report Failed', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info('Scheduled reports processing completed.');
        
        return 0;
    }
}
