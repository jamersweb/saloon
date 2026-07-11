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
        $expenseEntries = ExpenseEntry::query()->get();

        $invoiceDefaultBefore = $invoiceItems->where('cost_center', FinanceStructure::DEFAULT_COST_CENTER)->count();
        $expenseDefaultBefore = $expenseEntries->where('cost_center', FinanceStructure::DEFAULT_COST_CENTER)->count();

        $invoiceChanges = 0;
        $invoiceChangesByTarget = [];
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
                $invoiceChangesByTarget[$resolved] = ($invoiceChangesByTarget[$resolved] ?? 0) + 1;

                if (! $dryRun) {
                    $item->update(['cost_center' => $resolved]);
                }
            }
        }

        $expenseChanges = 0;
        $expenseChangesByTarget = [];
        foreach ($expenseEntries as $expense) {
            $resolved = FinanceStructure::defaultExpenseCostCenter(
                $expense->category,
                $expense->expense_subcategory
            );

            if (! FinanceStructure::isFallbackCostCenter($expense->cost_center) || $resolved === $expense->cost_center) {
                continue;
            }

            $expenseChanges++;
            $expenseChangesByTarget[$resolved] = ($expenseChangesByTarget[$resolved] ?? 0) + 1;

            if (! $dryRun) {
                $expense->update(['cost_center' => $resolved]);
            }
        }

        $invoiceDefaultAfter = $dryRun
            ? max(0, $invoiceDefaultBefore - array_sum(array_filter(
                $invoiceChangesByTarget,
                fn (int $count, string $target) => $target !== FinanceStructure::DEFAULT_COST_CENTER,
                ARRAY_FILTER_USE_BOTH
            )))
            : TaxInvoiceItem::query()->where('cost_center', FinanceStructure::DEFAULT_COST_CENTER)->count();

        $expenseDefaultAfter = $dryRun
            ? max(0, $expenseDefaultBefore - array_sum(array_filter(
                $expenseChangesByTarget,
                fn (int $count, string $target) => $target !== FinanceStructure::DEFAULT_COST_CENTER,
                ARRAY_FILTER_USE_BOTH
            )))
            : ExpenseEntry::query()->where('cost_center', FinanceStructure::DEFAULT_COST_CENTER)->count();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $dryRun ? 'Dry run' : 'Write changes'],
                ['Invoice lines needing cost-center change', $invoiceChanges],
                ['Expense rows needing cost-center change', $expenseChanges],
                ['Invoice lines on default cost center before', $invoiceDefaultBefore],
                ['Expense rows on default cost center before', $expenseDefaultBefore],
                ['Invoice lines on default cost center after', $invoiceDefaultAfter],
                ['Expense rows on default cost center after', $expenseDefaultAfter],
            ]
        );

        $this->renderBreakdownTable('Invoice line target cost centers', $invoiceChangesByTarget);
        $this->renderBreakdownTable('Expense row target cost centers', $expenseChangesByTarget);

        $this->info(sprintf(
            '%s invoice lines and %s expense rows %s.',
            $invoiceChanges,
            $expenseChanges,
            $dryRun ? 'would be updated' : 'updated'
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $rows
     */
    private function renderBreakdownTable(string $title, array $rows): void
    {
        if ($rows === []) {
            $this->line($title.': none');

            return;
        }

        $labels = FinanceStructure::costCenters();

        $this->newLine();
        $this->info($title);
        $this->table(
            ['Cost center', 'Label', 'Rows'],
            collect($rows)
                ->sortKeys()
                ->map(fn (int $count, string $key) => [
                    $key,
                    $labels[$key] ?? $key,
                    $count,
                ])
                ->values()
                ->all()
        );
    }
}
