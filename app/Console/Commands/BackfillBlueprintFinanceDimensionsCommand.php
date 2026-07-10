<?php

namespace App\Console\Commands;

use App\Models\ExpenseEntry;
use App\Models\TaxInvoiceItem;
use App\Support\FinanceStructure;
use Illuminate\Console\Command;

class BackfillBlueprintFinanceDimensionsCommand extends Command
{
    protected $signature = 'app:backfill-blueprint-finance-dimensions {--dry-run : Preview counts without writing changes}';

    protected $description = 'Backfill invoice-line and expense cost centers/categories using current blueprint rules';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $invoiceItems = TaxInvoiceItem::query()
            ->with('salonService:id,name,category')
            ->get();

        $invoiceChanges = 0;
        foreach ($invoiceItems as $item) {
            $current = $item->cost_center;
            $resolved = FinanceStructure::resolveInvoiceCostCenter(
                $item->cost_center,
                $item->salonService,
                $item->revenue_category,
                $item->description
            );

            if ($resolved !== $current) {
                $invoiceChanges++;

                if (! $dryRun) {
                    $item->update(['cost_center' => $resolved]);
                }
            }
        }

        $expenseEntries = ExpenseEntry::query()->get();
        $expenseChanges = 0;
        foreach ($expenseEntries as $expense) {
            $resolved = FinanceStructure::defaultExpenseCostCenter(
                $expense->category,
                $expense->expense_subcategory
            );

            if (! FinanceStructure::isFallbackCostCenter($expense->cost_center) || $resolved === $expense->cost_center) {
                continue;
            }

            $expenseChanges++;

            if (! $dryRun) {
                $expense->update(['cost_center' => $resolved]);
            }
        }

        $this->info(sprintf(
            '%s invoice lines and %s expense rows %s.',
            $invoiceChanges,
            $expenseChanges,
            $dryRun ? 'would be updated' : 'updated'
        ));

        return self::SUCCESS;
    }
}
