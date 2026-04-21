<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\SalonService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class CatalogImportSeeder extends Seeder
{
    private const DEFAULT_XLSX_PATH = 'C:/Users/Hp/OneDrive/Desktop/tut/CRM.xlsx';
    private const DEFAULT_LAKME_JSON_PATH = 'database/data/lakme_pricelist_2026.json';
    private const DEFAULT_CRM_SNAPSHOT_JSON_PATH = 'database/data/crm_catalog_snapshot.json';

    private const DEMO_SERVICE_NAMES = [
        'Luxury Haircut',
        'Bridal Makeup',
        'Hydrating Facial',
        'Nail Art Premium',
        'SERVICES',
    ];

    private const DEMO_PRODUCT_SKUS = [
        'INV-SHA-001',
        'INV-CON-001',
        'INV-COL-001',
        'INV-TOO-001',
        'INV-SER-001',
    ];

    public function run(): void
    {
        $path = env('CATALOG_IMPORT_XLSX', self::DEFAULT_XLSX_PATH);

        if (! is_file($path)) {
            $snapshotPath = base_path(self::DEFAULT_CRM_SNAPSHOT_JSON_PATH);
            if (! is_file($snapshotPath)) {
                $this->command?->warn("Catalog import skipped: file not found at {$path}");
                return;
            }

            $snapshotSheets = $this->readCrmSnapshotJson($snapshotPath);
            if ($snapshotSheets === []) {
                $this->command?->warn("Catalog import skipped: unable to read CRM snapshot at {$snapshotPath}");
                return;
            }

            $this->importFromSheets($snapshotSheets);
            return;
        }

        $sheets = $this->readXlsx($path);

        if ($sheets === []) {
            $snapshotPath = base_path(self::DEFAULT_CRM_SNAPSHOT_JSON_PATH);
            $snapshotSheets = is_file($snapshotPath) ? $this->readCrmSnapshotJson($snapshotPath) : [];
            if ($snapshotSheets !== []) {
                $this->importFromSheets($snapshotSheets);
                return;
            }

            $this->command?->warn("Catalog import skipped: unable to read {$path}");
            return;
        }

        $this->importFromSheets($sheets);
    }

    /**
     * @param array<string, array<int, array<string, string>>> $sheets
     */
    private function importFromSheets(array $sheets): void
    {
        $lakmeData = $this->readLakmeJson(base_path(self::DEFAULT_LAKME_JSON_PATH));
        $services = $this->extractServices($sheets['Sheet1'] ?? []);
        $products = $this->extractProducts($sheets['Sheet2'] ?? [], $lakmeData);

        $this->removeOldDemoSeedData();

        $importedServices = 0;
        foreach ($services as $service) {
            SalonService::query()->updateOrCreate(
                ['name' => $service['name']],
                $service
            );
            $importedServices++;
        }

        $importedProducts = 0;
        foreach ($products as $product) {
            InventoryItem::query()->updateOrCreate(
                ['sku' => $product['sku']],
                $product
            );
            $importedProducts++;
        }

        $this->command?->info("Catalog imported: {$importedServices} services, {$importedProducts} products.");
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function readCrmSnapshotJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $sheets = $decoded['sheets'] ?? null;
        return is_array($sheets) ? $sheets : [];
    }

    private function removeOldDemoSeedData(): void
    {
        SalonService::query()
            ->whereIn('name', self::DEMO_SERVICE_NAMES)
            ->whereDoesntHave('appointments')
            ->delete();

        SalonService::query()
            ->whereIn('name', self::DEMO_SERVICE_NAMES)
            ->whereHas('appointments')
            ->update(['is_active' => false]);

        InventoryItem::query()
            ->whereIn('sku', self::DEMO_PRODUCT_SKUS)
            ->whereDoesntHave('transactions')
            ->whereDoesntHave('appointmentProductUsages')
            ->whereDoesntHave('purchaseOrderItems')
            ->delete();

        InventoryItem::query()
            ->whereIn('sku', self::DEMO_PRODUCT_SKUS)
            ->update(['is_active' => false]);
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $workbookSheets = $this->readWorkbookSheets($zip);

            $rowsBySheetName = [];
            foreach ($workbookSheets as $sheetName => $targetPath) {
                $rowsBySheetName[$sheetName] = $this->readSheetRows($zip, $targetPath, $sharedStrings);
            }

            return $rowsBySheetName;
        } catch (\Throwable $e) {
            Log::warning('CatalogImportSeeder failed to parse xlsx.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $doc = new \SimpleXMLElement($xml);
        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($doc->xpath('//a:si') ?: [] as $si) {
            $si->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($si->xpath('.//a:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = trim(implode('', $parts));
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function readWorkbookSheets(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return [];
        }

        $workbook = new \SimpleXMLElement($workbookXml);
        $workbook->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $rels = new \SimpleXMLElement($relsXml);
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $targetsByRid = [];
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');
            if ($id !== '' && $target !== '') {
                $targetsByRid[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        $sheets = [];
        foreach ($workbook->xpath('//a:sheets/a:sheet') ?: [] as $sheet) {
            $sheetAttrs = $sheet->attributes();
            $sheetRelAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $name = (string) ($sheetAttrs['name'] ?? '');
            $rid = (string) ($sheetRelAttrs['id'] ?? '');
            if ($name !== '' && isset($targetsByRid[$rid])) {
                $sheets[$name] = $targetsByRid[$rid];
            }
        }

        return $sheets;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<string, string>>
     */
    private function readSheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            return [];
        }

        $sheet = new \SimpleXMLElement($xml);
        $sheet->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($sheet->xpath('//a:sheetData/a:row') ?: [] as $row) {
            $row->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cells = [];
            foreach ($row->xpath('./a:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cellAttrs = $cell->attributes();
                $cellRef = (string) ($cellAttrs['r'] ?? '');
                $cellType = (string) ($cellAttrs['t'] ?? '');
                $column = preg_replace('/\d+/', '', $cellRef) ?: '';

                $value = '';
                $vNode = $cell->xpath('./a:v');
                if ($vNode !== false && isset($vNode[0])) {
                    $rawValue = (string) $vNode[0];
                    if ($cellType === 's') {
                        $index = (int) $rawValue;
                        $value = $sharedStrings[$index] ?? '';
                    } else {
                        $value = $rawValue;
                    }
                }

                if ($column !== '') {
                    $cells[$column] = trim($value);
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function extractServices(array $rows): array
    {
        $services = [];
        $currentCategory = null;

        foreach ($rows as $row) {
            $category = trim((string) ($row['B'] ?? ''));
            $serviceName = trim((string) ($row['C'] ?? ''));
            $price = $this->toDecimal($row['D'] ?? null);

            if (strcasecmp($category, 'CATEGORIES') === 0 || strcasecmp($serviceName, 'SERVICES') === 0) {
                continue;
            }

            if ($category !== '' && $serviceName === '') {
                $currentCategory = $category;
                continue;
            }

            if ($serviceName === '') {
                continue;
            }

            $services[] = [
                'name' => $serviceName,
                'category' => $currentCategory,
                'duration_minutes' => $this->guessServiceDurationMinutes($serviceName, $currentCategory),
                'buffer_minutes' => 10,
                'repeat_after_days' => null,
                'price' => $price,
                'is_active' => true,
            ];
        }

        return $this->uniqueByName($services);
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @param array{items_by_sku: array<string, array<string, mixed>>, items_by_name: array<string, array<string, mixed>>} $lakmeData
     * @return array<int, array<string, mixed>>
     */
    private function extractProducts(array $rows, array $lakmeData): array
    {
        $products = [];
        $headerSeen = false;

        foreach ($rows as $row) {
            $upc = trim((string) ($row['A'] ?? ''));
            $name = trim((string) ($row['B'] ?? ''));
            $description = trim((string) ($row['C'] ?? ''));
            $rsp = $this->toDecimal($row['E'] ?? null);

            if (! $headerSeen) {
                if (strcasecmp($upc, 'UPC Code') === 0) {
                    $headerSeen = true;
                }
                continue;
            }

            if ($name === '') {
                continue;
            }

            $sku = $upc !== '' ? $upc : 'CRM-'.strtoupper(substr(sha1($name), 0, 10));
            $lakme = $this->matchLakmeProduct($sku, $name, $lakmeData);
            $sellingPrice = $lakme['selling_price'] ?? $rsp;
            $costPrice = $lakme['cost_price'] ?? ($sellingPrice > 0 ? round($sellingPrice * 0.6, 2) : 0.0);
            $category = $this->guessProductCategory($name, $description, $lakme['family'] ?? null);
            $unit = $this->guessProductUnit($name);

            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'stock_quantity' => 0,
                'reorder_level' => 0,
                'is_active' => true,
            ];
        }

        return $this->uniqueBySku($products);
    }

    /**
     * @param mixed $value
     */
    private function toDecimal(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $normalized = str_replace([',', ' '], '', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueByName(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $indexed[$name] = $row;
        }

        return array_values($indexed);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueBySku(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $sku = (string) ($row['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            $indexed[$sku] = $row;
        }

        return array_values($indexed);
    }

    /**
     * @return array{items_by_sku: array<string, array<string, mixed>>, items_by_name: array<string, array<string, mixed>>}
     */
    private function readLakmeJson(string $path): array
    {
        if (! is_file($path)) {
            return ['items_by_sku' => [], 'items_by_name' => []];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['items_by_sku' => [], 'items_by_name' => []];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['items_by_sku' => [], 'items_by_name' => []];
        }

        return [
            'items_by_sku' => is_array($decoded['items_by_sku'] ?? null) ? $decoded['items_by_sku'] : [],
            'items_by_name' => is_array($decoded['items_by_name'] ?? null) ? $decoded['items_by_name'] : [],
        ];
    }

    /**
     * @param array{items_by_sku: array<string, array<string, mixed>>, items_by_name: array<string, array<string, mixed>>} $lakmeData
     * @return array<string, mixed>|null
     */
    private function matchLakmeProduct(string $sku, string $name, array $lakmeData): ?array
    {
        if (isset($lakmeData['items_by_sku'][$sku])) {
            return $lakmeData['items_by_sku'][$sku];
        }

        $normalizedName = $this->normalizeKey($name);
        if ($normalizedName !== '' && isset($lakmeData['items_by_name'][$normalizedName])) {
            return $lakmeData['items_by_name'][$normalizedName];
        }

        return null;
    }

    private function guessServiceDurationMinutes(string $serviceName, ?string $category): int
    {
        $service = mb_strtolower(trim($serviceName));
        $cat = mb_strtolower(trim((string) $category));
        $haystack = trim($service.' '.$cat);

        if (str_contains($service, 'touchup') || str_contains($service, 'retouch')) {
            return 45;
        }
        if (str_contains($service, 'micro blading') || str_contains($service, 'combo brow') || str_contains($cat, 'micro blading')) {
            return 120;
        }
        if (str_contains($haystack, 'makeup') || str_contains($haystack, 'facial')) {
            return 90;
        }
        if (str_contains($haystack, 'nail')) {
            return 75;
        }
        if (str_contains($haystack, 'hair color') || str_contains($haystack, 'keratin')) {
            return 120;
        }
        if (str_contains($haystack, 'haircut') || str_contains($haystack, 'blow dry')) {
            return 60;
        }

        return 60;
    }

    private function guessProductCategory(string $name, string $description, ?string $family): ?string
    {
        if ($description !== '') {
            return $description;
        }
        if ($family !== null && $family !== '') {
            return $family;
        }

        $n = mb_strtolower($name);
        return match (true) {
            str_contains($n, 'shampoo') => 'Shampoo',
            str_contains($n, 'conditioner') => 'Conditioner',
            str_contains($n, 'mask') => 'Hair Mask',
            str_contains($n, 'developer'), str_contains($n, 'hydrox') => 'Developer/Oxidant',
            str_contains($n, 'color'), str_contains($n, 'collage'), str_contains($n, 'gloss'), str_contains($n, 'chroma') => 'Hair Color',
            str_contains($n, 'powder'), str_contains($n, 'bleach') => 'Bleaching',
            default => null,
        };
    }

    private function guessProductUnit(string $name): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            str_contains($n, ' ml') || str_contains($n, '1000ml') => 'ml',
            str_contains($n, ' gr') || str_contains($n, 'gm') => 'g',
            str_contains($n, ' box') => 'box',
            default => 'pcs',
        };
    }

    private function normalizeKey(string $value): string
    {
        $upper = mb_strtoupper($value);
        $upper = preg_replace('/\s+/', ' ', $upper) ?? '';
        return trim($upper);
    }
}
