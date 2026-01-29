<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Automation\Triggers\TriggerEvaluator;
use App\Services\Listing\ListingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncListingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-listings {--location_id= : ID of specific location to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync listing details from external platforms and detect discrepancies';

    public function __construct(
        protected ListingService $listingService,
        protected TriggerEvaluator $triggerEvaluator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting listing sync process...');

        $locationId = $this->option('location_id');

        $query = Location::with('tenant');
        if ($locationId) {
            $query->where('id', $locationId);
        }

        $locations = $query->get();
        $totalSynced = 0;
        $discrepanciesFound = 0;

        foreach ($locations as $location) {
            $this->info("Processing location: {$location->name} (ID: {$location->id})");

            try {
                $results = $this->listingService->syncAllPlatforms($location);

                foreach ($results as $platform => $listing) {
                    if (!$listing) {
                        continue;
                    }

                    $totalSynced++;
                    $this->info("  Synced {$platform}");

                    if ($listing->hasDiscrepancies()) {
                        $discrepanciesFound++;
                        $this->warn("  Discrepancy detected on {$platform}!");
                        
                        // Trigger automation
                        $this->triggerEvaluator->handleListingDiscrepancy([
                            'location_id' => $location->id,
                            'platform' => $platform,
                            'discrepancies' => $listing->discrepancies,
                            'tenant_id' => $location->tenant_id, // Ensure tenant context
                        ]);
                    }
                }

            } catch (\Exception $e) {
                $this->error("  Failed to sync location {$location->id}: " . $e->getMessage());
                Log::error('Listing Sync Error', [
                    'location_id' => $location->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Listing sync completed.");
        $this->info("Total Listings Synced: {$totalSynced}");
        $this->info("Discrepancies Found: {$discrepanciesFound}");
        
        return 0;
    }
}
