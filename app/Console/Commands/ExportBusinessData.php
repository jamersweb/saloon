<?php

namespace App\Console\Commands;

use App\Models\CustomerMembershipCard;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Console\Command;

class ExportBusinessData extends Command
{
    protected $signature = 'app:data-export
        {entity=all : all|users|inventory|membership_cards|gift_cards}
        {--path= : Full output file path (JSON)}';

    protected $description = 'Export users, inventory, membership card entries, and gift cards to JSON';

    public function handle(): int
    {
        $entity = strtolower((string) $this->argument('entity'));
        $entities = $this->normalizeEntities($entity);

        if ($entities === []) {
            $this->error('Invalid entity. Use all|users|inventory|membership_cards|gift_cards.');
            return self::INVALID;
        }

        $path = $this->resolvePath((string) $this->option('path'));
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'entities' => [],
        ];

        foreach ($entities as $item) {
            $payload['entities'][$item] = $this->exportEntity($item);
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Export completed: '.$path);

        foreach ($entities as $item) {
            $count = is_countable($payload['entities'][$item] ?? null) ? count($payload['entities'][$item]) : 0;
            $this->line("- {$item}: {$count}");
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

    private function resolvePath(string $optionPath): string
    {
        if ($optionPath !== '') {
            return $optionPath;
        }

        $name = 'business_export_'.now()->format('Ymd_His').'.json';
        return storage_path('app/exports/'.$name);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportEntity(string $entity): array
    {
        return match ($entity) {
            'users' => User::query()
                ->select(['id', 'name', 'email', 'password', 'role_id', 'email_verified_at', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => $user->toArray())
                ->all(),
            'inventory' => InventoryItem::query()
                ->select(['id', 'sku', 'name', 'category', 'unit', 'cost_price', 'selling_price', 'stock_quantity', 'reorder_level', 'is_active', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->get()
                ->map(fn (InventoryItem $item) => $item->toArray())
                ->all(),
            'membership_cards' => CustomerMembershipCard::query()
                ->select(['id', 'customer_id', 'membership_card_type_id', 'card_number', 'nfc_uid', 'status', 'issued_at', 'activated_at', 'expires_at', 'assigned_by', 'notes', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->get()
                ->map(fn (CustomerMembershipCard $card) => $card->toArray())
                ->all(),
            'gift_cards' => GiftCard::query()
                ->select(['id', 'code', 'nfc_uid', 'assigned_customer_id', 'initial_value', 'remaining_value', 'expires_at', 'status', 'issued_by', 'notes', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->get()
                ->map(fn (GiftCard $card) => $card->toArray())
                ->all(),
            default => [],
        };
    }
}

