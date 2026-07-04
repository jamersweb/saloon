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
            $existingLine = PayrollLine::query()
                ->where('payroll_period_id', $period->id)
                ->where('staff_profile_id', $profile->id)
                ->first();

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
            $payBasis = (float) ($profile->monthly_salary ?? 0) > 0
                ? PayrollLine::PAY_BASIS_FIXED
                : PayrollLine::PAY_BASIS_HOURLY;
            $basicSalary = $payBasis === PayrollLine::PAY_BASIS_FIXED
                ? round((float) $profile->monthly_salary, 2)
                : round($hours * $rate, 2);
            $bonusAmount = round((float) ($existingLine?->bonus_amount ?? 0), 2);
            $deductionAmount = round((float) ($existingLine?->deduction_amount ?? 0), 2);
            $gross = round($basicSalary + $bonusAmount, 2);
            $net = round(max(0, $gross - $deductionAmount), 2);

            PayrollLine::query()->updateOrCreate(
                [
                    'payroll_period_id' => $period->id,
                    'staff_profile_id' => $profile->id,
                ],
                [
                    'pay_basis' => $payBasis,
                    'hours_worked' => $hours,
                    'hourly_rate' => $rate,
                    'basic_salary' => $basicSalary,
                    'gross_amount' => $gross,
                    'bonus_amount' => $bonusAmount,
                    'deduction_amount' => $deductionAmount,
                    'net_amount' => $net,
                    'payment_method' => $existingLine?->payment_method ?? 'bank_transfer',
                    'notes' => $existingLine?->notes,
                ]
            );
        }
    }
}
