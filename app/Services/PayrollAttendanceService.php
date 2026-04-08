<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\StaffProfile;
use Carbon\Carbon;

class PayrollAttendanceService
{
    public function syncLinesForPeriod(PayrollPeriod $period): void
    {
        $start = $period->period_start->toDateString();
        $end = $period->period_end->toDateString();

        $profiles = StaffProfile::query()
            ->where('is_active', true)
            ->get();

        foreach ($profiles as $profile) {
            $logs = AttendanceLog::query()
                ->where('staff_profile_id', $profile->id)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('clock_in')
                ->whereNotNull('clock_out')
                ->get();

            $hours = 0.0;
            foreach ($logs as $log) {
                $date = $log->attendance_date->format('Y-m-d');
                $in = Carbon::parse($date.' '.$log->clock_in);
                $out = Carbon::parse($date.' '.$log->clock_out);
                if ($out->greaterThan($in)) {
                    $hours += $in->diffInMinutes($out) / 60;
                }
            }

            $hours = round($hours, 2);
            $rate = (float) ($profile->hourly_rate ?? 0);
            $gross = round($hours * $rate, 2);

            PayrollLine::query()->updateOrCreate(
                [
                    'payroll_period_id' => $period->id,
                    'staff_profile_id' => $profile->id,
                ],
                [
                    'hours_worked' => $hours,
                    'hourly_rate' => $rate,
                    'gross_amount' => $gross,
                ]
            );
        }
    }
}
