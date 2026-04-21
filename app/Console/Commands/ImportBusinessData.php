<?php

namespace App\Console\Commands;

use App\Models\CustomerMembershipCard;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Console\Command;

class ImportBusinessData extends Command
{
    protected $signature = 'app:data-import
        {file : JSON export file path}
        {entity=all : all|users|inventory|membership_cards|gift_cards}';

    protected $description = 'Import users, inventory, membership card entries, and gift cards from JSON';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $entity = strtolower((string) $this->argument('entity'));
        $entities = $this->normalizeEntities($entity);

        if (! is_file($file)) {
            $this->error('Import file not found: '.$file);
            return self::FAILURE;
        }

        if ($entities === []) {
            $this->error('Invalid entity. Use all|users|inventory|membership_cards|gift_cards.');
            return self::INVALID;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded) || ! is_array($decoded['entities'] ?? null)) {
            $this->error('Invalid JSON structure. Expected root.entities object.');
            return self::FAILURE;
        }

        $inputEntities = $decoded['entities'];

        foreach ($entities as $name) {
            $rows = $inputEntities[$name] ?? [];
            if (! is_array($rows)) {
                $this->warn("Skipping {$name}: invalid payload.");
                continue;
            }

            $count = $this->importEntity($name, $rows);
            $this->info("Imported {$name}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeEntities(string $entity): array
    {
        $allowed = ['users', 'inventory', 'membership_cards', 'gift_cards'];

        if ($entity === 'all') {
            return $allowed;
        }

        return in_array($entity, $allowed, true) ? [$entity] : [];
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function importEntity(string $entity, array $rows): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ok = match ($entity) {
                'users' => $this->importUser($row),
                'inventory' => $this->importInventory($row),
                'membership_cards' => $this->importMembershipCard($row),
                'gift_cards' => $this->importGiftCard($row),
                default => false,
            };

            if ($ok) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importUser(array $row): bool
    {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            return false;
        }

        $roleId = isset($row['role_id']) ? (int) $row['role_id'] : null;
        if (! $roleId) {
            return false;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) ($row['name'] ?? ''),
                'password' => (string) ($row['password'] ?? ''),
                'role_id' => $roleId,
                'email_verified_at' => $row['email_verified_at'] ?? null,
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importInventory(array $row): bool
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku === '') {
            return false;
        }

        InventoryItem::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'name' => (string) ($row['name'] ?? ''),
                'category' => $row['category'] ?? null,
                'unit' => (string) ($row['unit'] ?? 'pcs'),
                'cost_price' => $this->toDecimal($row['cost_price'] ?? null),
                'selling_price' => $this->toDecimal($row['selling_price'] ?? null),
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'reorder_level' => (int) ($row['reorder_level'] ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importMembershipCard(array $row): bool
    {
        $cardNumber = trim((string) ($row['card_number'] ?? ''));
        if ($cardNumber === '') {
            return false;
        }

        $customerId = isset($row['customer_id']) ? (int) $row['customer_id'] : null;
        $typeId = isset($row['membership_card_type_id']) ? (int) $row['membership_card_type_id'] : null;

        if (! $typeId) {
            return false;
        }

        CustomerMembershipCard::query()->updateOrCreate(
            ['card_number' => $cardNumber],
            [
                'customer_id' => $customerId ?: null,
                'membership_card_type_id' => $typeId,
                'nfc_uid' => $row['nfc_uid'] ?? null,
                'status' => (string) ($row['status'] ?? 'active'),
                'issued_at' => $row['issued_at'] ?? now(),
                'activated_at' => $row['activated_at'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
                'assigned_by' => isset($row['assigned_by']) ? (int) $row['assigned_by'] : null,
                'notes' => $row['notes'] ?? null,
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importGiftCard(array $row): bool
    {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            return false;
        }

        GiftCard::query()->updateOrCreate(
            ['code' => $code],
            [
                'nfc_uid' => $row['nfc_uid'] ?? null,
                'assigned_customer_id' => isset($row['assigned_customer_id']) ? (int) $row['assigned_customer_id'] : null,
                'initial_value' => $this->toDecimal($row['initial_value'] ?? null),
                'remaining_value' => $this->toDecimal($row['remaining_value'] ?? null),
                'expires_at' => $row['expires_at'] ?? null,
                'status' => (string) ($row['status'] ?? 'active'),
                'issued_by' => isset($row['issued_by']) ? (int) $row['issued_by'] : null,
                'notes' => $row['notes'] ?? null,
            ]
        );

        return true;
    }

    /**
     * @param mixed $value
     */
    private function toDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = str_replace([',', ' '], '', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}

