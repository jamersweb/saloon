<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignDispatchService;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dispatch-scheduled-campaigns {--limit=25}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch queued/scheduled CRM campaigns';

    /**
     * Execute the console command.
     */
    public function handle(CampaignDispatchService $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $campaigns = Campaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No scheduled campaigns due.');
            return self::SUCCESS;
        }

        foreach ($campaigns as $campaign) {
            $result = $dispatcher->dispatch($campaign);
            $this->info("Campaign #{$campaign->id} dispatched. Sent: {$result['sent']}, Failed: {$result['failed']}");
        }

        return self::SUCCESS;
    }
}

