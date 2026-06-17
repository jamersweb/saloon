<?php

namespace App\Console\Commands;

use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApplyVinaStaffScheduleCommand extends Command
{
    protected $signature = 'staff:schedule-vina-shifts
        {--start= : Start month in YYYY-MM format. Defaults to the current month.}
        {--months=2 : Number of calendar months to update.}
        {--dry-run : Show what would change without writing to the database.}';

    protected $description = 'Apply Vina staff fixed shifts for the current and next month.';

    /** @var list<string> */
    private array $morningNames = [
        'Mona Bassagh',
        'Sahar Shams',
        'Analisa Rabanal Domenden',
        'Jocelyn Caburnay Caquista',
        'Jenifer Palisoc Jazmin',
        'Hengameh Dortaj',
    ];

    /** @var list<string> */
    private array $eveningNames = [
        'Dulce Aguilar',
        'Majd Alabaza',
    ];

    public function handle(): int
    {
        $start = $this->resolveStartMonth();
        $months = max(1, (int) $this->option('months'));
        $end = $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth();
        $dryRun = (bool) $this->option('dry-run');
        $profiles = $this->profilesByName();

        $created = 0;
        $updated = 0;

        $apply = function () use ($start, $end, $profiles, &$created, &$updated): void {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $this->applyDate($date, $profiles, $created, $updated);
            }
        };

        if ($dryRun) {
            DB::transaction(function () use ($apply): void {
                $apply();
                DB::rollBack();
            });
        } else {
            DB::transaction($apply);
        }

        $this->info(($dryRun ? '[Dry run] ' : '')."Vina staff schedule {$start->toDateString()} through {$end->toDateString()}.");
        $this->line("Rows created: {$created}");
        $this->line("Rows updated: {$updated}");

        return self::SUCCESS;
    }

    private function resolveStartMonth(): Carbon
    {
        $option = $this->option('start');

        if ($option) {
            return Carbon::createFromFormat('Y-m', (string) $option)->startOfMonth();
        }

        return Carbon::today()->startOfMonth();
    }

    /**
     * @return array<string, StaffProfile>
     */
    private function profilesByName(): array
    {
        $names = array_merge($this->morningNames, $this->eveningNames);
        $profiles = StaffProfile::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->whereIn('name', $names))
            ->get()
            ->keyBy(fn (StaffProfile $profile) => $profile->user?->name)
            ->all();

        $missing = array_values(array_diff($names, array_keys($profiles)));
        if ($missing !== []) {
            throw new RuntimeException('Missing staff profiles: '.implode(', ', $missing));
        }

        return $profiles;
    }

    /**
     * @param array<string, StaffProfile> $profiles
     */
    private function applyDate(Carbon $date, array $profiles, int &$created, int &$updated): void
    {
        foreach ($this->morningNames as $name) {
            $isHengamehOff = $name === 'Hengameh Dortaj' && ($date->isWednesday() || $date->isFriday());
            $attributes = ($date->isSunday() || $isHengamehOff)
                ? $this->offAttributes($date->isSunday() ? 'Salon closed Sunday' : 'Regular weekly off')
                : $this->shiftAttributes('10:00:00', '19:00:00', 'Regular shift 10AM-7PM');

            $this->upsertSchedule($profiles[$name], $date, $attributes, $created, $updated);
        }

        foreach ($this->eveningNames as $name) {
            $attributes = $date->isSunday()
                ? $this->offAttributes('Salon closed Sunday')
                : $this->shiftAttributes('13:00:00', '22:00:00', 'Regular shift 1PM-10PM');

            $this->upsertSchedule($profiles[$name], $date, $attributes, $created, $updated);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function offAttributes(string $notes): array
    {
        return [
            'start_time' => null,
            'end_time' => null,
            'break_start' => null,
            'break_end' => null,
            'is_day_off' => true,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftAttributes(string $start, string $end, string $notes): array
    {
        return [
            'start_time' => $start,
            'end_time' => $end,
            'break_start' => null,
            'break_end' => null,
            'is_day_off' => false,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function upsertSchedule(StaffProfile $profile, Carbon $date, array $attributes, int &$created, int &$updated): void
    {
        $row = StaffSchedule::query()->updateOrCreate(
            [
                'staff_profile_id' => $profile->id,
                'schedule_date' => $date->toDateString(),
            ],
            $attributes,
        );

        $row->wasRecentlyCreated ? $created++ : $updated++;
    }
}
