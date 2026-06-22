<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\SalonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplaceCatalogFromPdfData extends Command
{
    protected $signature = 'app:catalog-replace-from-pdf
        {file=database/data/pdf_catalog_2026_06_22.json : Catalog JSON generated from services and inventory PDFs}';

    protected $description = 'Deactivate current services/inventory and activate the catalog imported from PDF data';

    public function handle(): int
    {
        $file = base_path((string) $this->argument('file'));

        if (! is_file($file)) {
            $this->error('Catalog JSON not found: '.$file);
            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            $this->error('Catalog JSON is invalid.');
            return self::FAILURE;
        }

        $services = $this->validRows($decoded['services'] ?? []);
        $inventory = $this->validRows($decoded['inventory'] ?? []);

        if ($services === [] && $inventory === []) {
            $this->error('Catalog JSON has no services or inventory rows.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($services, $inventory): void {
            if ($services !== []) {
                $serviceNames = collect($services)
                    ->map(fn (array $row) => trim((string) ($row['name'] ?? '')))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                SalonService::query()
                    ->whereNotIn('name', $serviceNames)
                    ->update(['is_active' => false]);

                foreach ($services as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    SalonService::query()->updateOrCreate(
                        ['name' => $name],
                        [
                            'category' => $row['category'] ?? null,
                            'duration_minutes' => max(1, (int) ($row['duration_minutes'] ?? 60)),
                            'buffer_minutes' => max(0, (int) ($row['buffer_minutes'] ?? 10)),
                            'repeat_after_days' => isset($row['repeat_after_days']) ? (int) $row['repeat_after_days'] : null,
                            'price' => $this->decimal($row['price'] ?? 0),
                            'is_active' => true,
                        ],
                    );
                }
            }

            if ($inventory !== []) {
                $skus = collect($inventory)
                    ->map(fn (array $row) => trim((string) ($row['sku'] ?? '')))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                InventoryItem::query()
                    ->whereNotIn('sku', $skus)
                    ->update(['is_active' => false]);

                foreach ($inventory as $row) {
                    $sku = trim((string) ($row['sku'] ?? ''));
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($sku === '' || $name === '') {
                        continue;
                    }

                    InventoryItem::query()->updateOrCreate(
                        ['sku' => $sku],
                        [
                            'name' => $name,
                            'category' => $row['category'] ?? null,
                            'unit' => (string) ($row['unit'] ?? 'pcs'),
                            'cost_price' => $this->decimal($row['cost_price'] ?? 0),
                            'selling_price' => $this->decimal($row['selling_price'] ?? 0),
                            'stock_quantity' => max(0, (int) ($row['stock_quantity'] ?? 0)),
                            'reorder_level' => max(0, (int) ($row['reorder_level'] ?? 0)),
                            'is_active' => true,
                        ],
                    );
                }
            }
        });

        $this->info('Services active from PDF: '.count($services));
        $this->info('Inventory active from PDF: '.count($inventory));

        return self::SUCCESS;
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function validRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace([',', ' '], '', (string) ($value ?? 0));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}
