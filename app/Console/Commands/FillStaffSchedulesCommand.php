<?php

namespace App\Console\Commands;

use App\Services\StaffScheduleGeneratorService;
use Illuminate\Console\Command;

class FillStaffSchedulesCommand extends Command
{
    protected $signature = 'schedules:fill {--days=31 : Fill missing schedule rows from today through this many days ahead (inclusive of today)}';

    protected $description = 'Create default staff schedule rows for upcoming dates when missing (respects approved leave as day off).';

    public function handle(StaffScheduleGeneratorService $generator): int
    {
        $days = max(1, min(120, (int) $this->option('days')));
        $start = now()->startOfDay();
        $end = now()->copy()->addDays($days - 1)->startOfDay();

        $created = $generator->fillGapsForActiveStaff($start, $end);

        $this->info("Filled {$created} missing schedule slot(s) from {$start->toDateString()} to {$end->toDateString()}.");

        return self::SUCCESS;
    }
}
