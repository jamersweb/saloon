<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'slot_interval_minutes',
        'opening_time',
        'closing_time',
        'min_advance_minutes',
        'max_advance_days',
        'public_requires_approval',
        'allow_customer_cancellation',
        'cancellation_cutoff_hours',
    ];

    protected function casts(): array
    {
        return [
            'public_requires_approval' => 'boolean',
            'allow_customer_cancellation' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'slot_interval_minutes' => 30,
                'opening_time' => '09:00',
                'closing_time' => '22:00',
                'min_advance_minutes' => 30,
                'max_advance_days' => 60,
                'public_requires_approval' => true,
                'allow_customer_cancellation' => true,
                'cancellation_cutoff_hours' => 12,
            ]
        );
    }

    /** Salon opening on the given calendar day (local). */
    public function salonOpenOn(Carbon $onDay): Carbon
    {
        return Carbon::parse($onDay->toDateString().' '.$this->normalizedClock($this->opening_time, '09:00'));
    }

    /** Salon closing on the given calendar day (local). Appointments must end by this instant. */
    public function salonCloseOn(Carbon $onDay): Carbon
    {
        return Carbon::parse($onDay->toDateString().' '.$this->normalizedClock($this->closing_time, '22:00'));
    }

    /** Default shift start (H:i) for new staff schedules and forms. */
    public function defaultShiftStart(): string
    {
        return substr($this->normalizedClock($this->opening_time, '09:00'), 0, 5);
    }

    /** Default shift end (H:i) for new staff schedules and forms. */
    public function defaultShiftEnd(): string
    {
        return substr($this->normalizedClock($this->closing_time, '22:00'), 0, 5);
    }

    /**
     * Next suggested appointment start (datetime-local string) respecting advance, slot interval, and salon hours.
     */
    public function nextDefaultAppointmentStart(?Carbon $now = null): string
    {
        $now = $now ?? now();
        $safeInterval = max(1, (int) $this->slot_interval_minutes);
        $base = $now->copy()
            ->addMinutes(max(0, (int) $this->min_advance_minutes))
            ->setSeconds(0);

        $minutes = (int) $base->format('i');
        $remainder = $minutes % $safeInterval;
        if ($remainder !== 0) {
            $base->addMinutes($safeInterval - $remainder);
        }

        $open = $this->salonOpenOn($base);
        $close = $this->salonCloseOn($base);

        if ($base->lt($open)) {
            $base = $open->copy();
            $minutes = (int) $base->format('i');
            $remainder = $minutes % $safeInterval;
            if ($remainder !== 0) {
                $base->addMinutes($safeInterval - $remainder);
            }
        }

        if ($base->gte($close)) {
            $base = $this->salonOpenOn($base->copy()->addDay());
            $minutes = (int) $base->format('i');
            $remainder = $minutes % $safeInterval;
            if ($remainder !== 0) {
                $base->addMinutes($safeInterval - $remainder);
            }
        }

        return $base->format('Y-m-d\TH:i');
    }

    /**
     * @param  mixed  $value
     */
    private function normalizedClock($value, string $fallback): string
    {
        $raw = is_string($value) && $value !== '' ? $value : $fallback;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
        }

        return $fallback.':00';
    }
}
