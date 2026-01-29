<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Console\Command;

class RunAutomationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pending automation workflows';

    public function __construct(
        protected TriggerEvaluator $triggerEvaluator
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running automation workflows...');
        
        // Trigger scheduled workflows
        // The trigger evaluator will find workflows configured with 'scheduled' trigger
        // and check if their conditions match (e.g., specific time/day)
        try {
            $this->triggerEvaluator->handleScheduledTrigger('cron', [
                'timestamp' => now()->timestamp,
                'datetime' => now()->toIso8601String(),
            ]);
            
            $this->info('Scheduled automation triggers processed.');
        } catch (\Exception $e) {
            $this->error('Failed to run automation: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}
