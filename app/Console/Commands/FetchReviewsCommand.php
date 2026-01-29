<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Services\Review\FacebookReviewService;
use App\Services\Review\GooglePlayStoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchReviewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-reviews';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch new reviews from configured platforms';

    public function __construct(
        protected GooglePlayStoreService $googlePlayService,
        protected FacebookReviewService $facebookService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting review fetch process...');

        $locations = Location::with('tenant')->get();
        $totalSynced = 0;

        foreach ($locations as $location) {
            $this->info("Processing location: {$location->name} (ID: {$location->id})");

            // 1. Google Play Store
            if ($location->google_play_package_name) {
                try {
                    $this->info("  Fetching Google Play reviews for package: {$location->google_play_package_name}");
                    $count = $this->googlePlayService->syncReviews($location);
                    $totalSynced += $count;
                    $this->info("  Synced {$count} Google Play reviews.");
                } catch (\Exception $e) {
                    $this->error("  Failed to sync Google Play reviews: " . $e->getMessage());
                    Log::error('Review Fetch Error (Google Play)', ['location_id' => $location->id, 'error' => $e->getMessage()]);
                }
            }

            // 2. Facebook
            try {
                $credential = PlatformCredential::getForTenant($location->tenant, 'facebook');
                if ($credential && $credential->isValid()) {
                    $this->info("  Fetching Facebook reviews...");
                    $count = $this->facebookService->syncFacebookReviews($location, $credential);
                    $totalSynced += $count;
                    $this->info("  Synced {$count} Facebook reviews.");
                } else {
                    $this->warn("  No valid Facebook credentials found for tenant {$location->tenant_id}");
                }
            } catch (\Exception $e) {
                $this->error("  Failed to sync Facebook reviews: " . $e->getMessage());
                Log::error('Review Fetch Error (Facebook)', ['location_id' => $location->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Review fetch completed. Total reviews synced: {$totalSynced}");
        
        return 0;
    }
}
