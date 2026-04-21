<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class LakmePriceListPdfSeeder extends Seeder
{
    private const DATA_PATH = 'database/data/lakme_pricelist_pdf_2026.json';

    public function run(): void
    {
        $path = base_path(self::DATA_PATH);
        if (! is_file($path)) {
            $this->command?->warn('Lakme PDF seeder skipped: data file not found at '.$path);
            return;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->command?->warn('Lakme PDF seeder skipped: unable to read data file.');
            return;
        }

        $decoded = json_decode($raw, true);
        $items = is_array($decoded) && is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        if ($items === []) {
            $this->command?->warn('Lakme PDF seeder skipped: no items found in data file.');
            return;
        }

        $count = 0;
        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($sku === '' || $name === '') {
                continue;
            }

            InventoryItem::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'category' => (string) ($item['category'] ?? 'Lakme Products'),
                    'unit' => (string) ($item['unit'] ?? 'pcs'),
                    'cost_price' => (float) ($item['cost_price'] ?? 0),
                    'selling_price' => (float) ($item['selling_price'] ?? 0),
                    'stock_quantity' => (int) ($item['stock_quantity'] ?? 0),
                    'reorder_level' => (int) ($item['reorder_level'] ?? 0),
                    'is_active' => (bool) ($item['is_active'] ?? true),
                ]
            );
            $count++;
        }

        $this->command?->info("Lakme PDF catalog seeded: {$count} items.");
    }
}

