<?php

namespace App\Services;

class TaxInvoiceLineCalculator
{
    /**
     * @return array{line_subtotal: float, line_tax: float, line_total: float, tax_rate_percent: float}
     */
    public static function compute(float $quantity, float $unitPrice, float $vatRatePercent, float $discountAmount = 0): array
    {
        $grossSubtotal = round($quantity * $unitPrice, 2);
        $discountAmount = round(max(0, min($discountAmount, $grossSubtotal)), 2);
        $lineSubtotal = round($grossSubtotal - $discountAmount, 2);
        $lineTax = round($lineSubtotal * ($vatRatePercent / 100), 2);
        $lineTotal = round($lineSubtotal + $lineTax, 2);

        return [
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => $lineTotal,
            'tax_rate_percent' => $vatRatePercent,
        ];
    }
}
