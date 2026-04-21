<?php

namespace App\Http\Controllers;

use App\Models\CustomerMembershipCard;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferController extends Controller
{
    private const ALLOWED_ENTITIES = ['users', 'customers', 'appointments', 'inventory', 'membership_cards', 'gift_cards'];

    public function export(Request $request, string $entity): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');
        $entity = strtolower($entity);
        abort_unless(in_array($entity, self::ALLOWED_ENTITIES, true), 404);

        $filename = $entity.'_export_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($entity): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $headers = $this->csvHeaders($entity);
            fputcsv($out, $headers);

            foreach ($this->rowsForExport($entity) as $row) {
                $ordered = [];
                foreach ($headers as $column) {
                    $ordered[] = $row[$column] ?? null;
                }
                fputcsv($out, $ordered);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function template(Request $request, string $entity): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');
        $entity = strtolower($entity);
        abort_unless(in_array($entity, self::ALLOWED_ENTITIES, true), 404);

        $filename = $entity.'_template.csv';
        $headers = $this->csvHeaders($entity);

        return response()->streamDownload(function () use ($headers): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, string $entity): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');
        $entity = strtolower($entity);
        abort_unless(in_array($entity, self::ALLOWED_ENTITIES, true), 404);

        $data = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $path = $data['csv_file']->getRealPath();
        if (! is_string($path) || $path === '') {
            return back()->withErrors(['csv_file' => 'Could not read uploaded file.']);
        }

        [$rows, $parseErrors] = $this->readCsvAssoc($path);
        if ($rows === []) {
            $message = $parseErrors[0] ?? 'CSV is empty or invalid.';
            return back()->withErrors(['csv_file' => $message]);
        }

        $imported = 0;
        $rowErrors = [];

        DB::transaction(function () use ($entity, $rows, &$imported, &$rowErrors): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                try {
                    $ok = match ($entity) {
                        'users' => $this->importUserRow($row),
                        'customers' => $this->importCustomerRow($row),
                        'appointments' => $this->importAppointmentRow($row),
                        'inventory' => $this->importInventoryRow($row),
                        'membership_cards' => $this->importMembershipCardRow($row),
                        'gift_cards' => $this->importGiftCardRow($row),
                        default => false,
                    };
                } catch (\Throwable $e) {
                    $ok = false;
                    $rowErrors[] = "Line {$line}: ".$e->getMessage();
                }

                if ($ok) {
                    $imported++;
                } else {
                    $rowErrors[] = "Line {$line}: skipped due to missing/invalid required values.";
                }
            }
        });

        if ($imported === 0) {
            return back()->withErrors(['csv_file' => $rowErrors[0] ?? 'No rows imported.']);
        }

        $status = "Imported {$imported} {$entity} row(s).";
        if ($rowErrors !== []) {
            $status .= ' '.count($rowErrors).' row(s) skipped.';
        }

        return back()->with('status', $status);
    }

    /**
     * @return array<int, string>
     */
    private function csvHeaders(string $entity): array
    {
        return match ($entity) {
            'users' => ['name', 'email', 'role_name', 'password'],
            'customers' => ['name', 'phone', 'email', 'birthday', 'allergies', 'notes', 'acquisition_source', 'is_active'],
            'appointments' => ['customer_id', 'service_id', 'staff_profile_id', 'status', 'scheduled_start', 'scheduled_end', 'customer_name', 'customer_phone', 'customer_email', 'notes'],
            'inventory' => ['sku', 'name', 'category', 'unit', 'cost_price', 'selling_price', 'stock_quantity', 'reorder_level', 'is_active'],
            'membership_cards' => ['customer_id', 'membership_card_type_id', 'card_number', 'nfc_uid', 'status', 'issued_at', 'activated_at', 'expires_at', 'assigned_by', 'notes'],
            'gift_cards' => ['code', 'nfc_uid', 'assigned_customer_id', 'initial_value', 'remaining_value', 'expires_at', 'status', 'issued_by', 'notes'],
            default => [],
        };
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function rowsForExport(string $entity): iterable
    {
        return match ($entity) {
            'users' => User::query()
                ->with('role:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_name' => $user->role?->name,
                    'password' => '',
                ])
                ->all(),
            'customers' => Customer::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Customer $customer) => [
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'birthday' => $customer->birthday?->format('Y-m-d'),
                    'allergies' => $customer->allergies,
                    'notes' => $customer->notes,
                    'acquisition_source' => $customer->acquisition_source,
                    'is_active' => $customer->is_active ? 1 : 0,
                ])
                ->all(),
            'appointments' => Appointment::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'customer_id' => $appointment->customer_id,
                    'service_id' => $appointment->service_id,
                    'staff_profile_id' => $appointment->staff_profile_id,
                    'status' => $appointment->status,
                    'scheduled_start' => $appointment->scheduled_start?->toDateTimeString(),
                    'scheduled_end' => $appointment->scheduled_end?->toDateTimeString(),
                    'customer_name' => $appointment->customer_name,
                    'customer_phone' => $appointment->customer_phone,
                    'customer_email' => $appointment->customer_email,
                    'notes' => $appointment->notes,
                ])
                ->all(),
            'inventory' => InventoryItem::query()
                ->orderBy('id')
                ->get()
                ->map(fn (InventoryItem $item) => [
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'cost_price' => $item->cost_price,
                    'selling_price' => $item->selling_price,
                    'stock_quantity' => $item->stock_quantity,
                    'reorder_level' => $item->reorder_level,
                    'is_active' => $item->is_active ? 1 : 0,
                ])
                ->all(),
            'membership_cards' => CustomerMembershipCard::query()
                ->orderBy('id')
                ->get()
                ->map(fn (CustomerMembershipCard $card) => [
                    'customer_id' => $card->customer_id,
                    'membership_card_type_id' => $card->membership_card_type_id,
                    'card_number' => $card->card_number,
                    'nfc_uid' => $card->nfc_uid,
                    'status' => $card->status,
                    'issued_at' => $card->issued_at?->toDateTimeString(),
                    'activated_at' => $card->activated_at?->toDateTimeString(),
                    'expires_at' => $card->expires_at?->toDateTimeString(),
                    'assigned_by' => $card->assigned_by,
                    'notes' => $card->notes,
                ])
                ->all(),
            'gift_cards' => GiftCard::query()
                ->orderBy('id')
                ->get()
                ->map(fn (GiftCard $card) => [
                    'code' => $card->code,
                    'nfc_uid' => $card->nfc_uid,
                    'assigned_customer_id' => $card->assigned_customer_id,
                    'initial_value' => $card->initial_value,
                    'remaining_value' => $card->remaining_value,
                    'expires_at' => $card->expires_at?->toDateTimeString(),
                    'status' => $card->status,
                    'issued_by' => $card->issued_by,
                    'notes' => $card->notes,
                ])
                ->all(),
            default => [],
        };
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: array<int, string>}
     */
    private function readCsvAssoc(string $path): array
    {
        $rows = [];
        $errors = [];
        $file = new \SplFileObject($path);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

        $headers = null;
        foreach ($file as $line) {
            if (! is_array($line)) {
                continue;
            }

            $line = array_map(fn ($v) => is_string($v) ? trim($v) : '', $line);
            if ($headers === null) {
                $headers = $line;
                continue;
            }

            if ($line === [null] || $line === ['']) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $key) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                $assoc[$key] = (string) ($line[$i] ?? '');
            }
            $rows[] = $assoc;
        }

        if ($headers === null || $headers === []) {
            $errors[] = 'CSV header row is missing.';
        }

        return [$rows, $errors];
    }

    /**
     * @param array<string, string> $row
     */
    private function importUserRow(array $row): bool
    {
        $email = trim((string) ($row['email'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $roleName = trim((string) ($row['role_name'] ?? ''));

        if ($email === '' || $name === '' || $roleName === '') {
            return false;
        }

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            return false;
        }

        $payload = [
            'name' => $name,
            'role_id' => (int) $roleId,
        ];

        $password = trim((string) ($row['password'] ?? ''));
        if ($password !== '') {
            $payload['password'] = $password;
        }

        User::query()->updateOrCreate(['email' => $email], $payload);
        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function importInventoryRow(array $row): bool
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($sku === '' || $name === '') {
            return false;
        }

        InventoryItem::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'category' => $this->nullable($row['category'] ?? null),
                'unit' => trim((string) ($row['unit'] ?? 'pcs')) ?: 'pcs',
                'cost_price' => $this->toDecimal($row['cost_price'] ?? null),
                'selling_price' => $this->toDecimal($row['selling_price'] ?? null),
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'reorder_level' => (int) ($row['reorder_level'] ?? 0),
                'is_active' => $this->toBool($row['is_active'] ?? '1'),
            ]
        );

        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function importCustomerRow(array $row): bool
    {
        $name = trim((string) ($row['name'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            return false;
        }

        Customer::query()->updateOrCreate(
            ['phone' => $phone],
            [
                'customer_code' => Customer::query()->where('phone', $phone)->value('customer_code') ?: ('CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999)),
                'name' => $name,
                'email' => $this->nullable($row['email'] ?? null),
                'birthday' => $this->nullable($row['birthday'] ?? null),
                'allergies' => $this->nullable($row['allergies'] ?? null),
                'notes' => $this->nullable($row['notes'] ?? null),
                'acquisition_source' => $this->nullable($row['acquisition_source'] ?? null),
                'is_active' => $this->toBool($row['is_active'] ?? '1'),
            ]
        );

        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function importAppointmentRow(array $row): bool
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        $serviceId = (int) ($row['service_id'] ?? 0);
        $start = $this->nullable($row['scheduled_start'] ?? null);
        if ($customerId <= 0 || $serviceId <= 0 || $start === null) {
            return false;
        }

        Appointment::query()->updateOrCreate(
            [
                'customer_id' => $customerId,
                'service_id' => $serviceId,
                'scheduled_start' => $start,
            ],
            [
                'staff_profile_id' => $this->toNullableInt($row['staff_profile_id'] ?? null),
                'status' => $this->nullable($row['status'] ?? null) ?? Appointment::STATUS_CONFIRMED,
                'scheduled_end' => $this->nullable($row['scheduled_end'] ?? null),
                'customer_name' => $this->nullable($row['customer_name'] ?? null),
                'customer_phone' => $this->nullable($row['customer_phone'] ?? null),
                'customer_email' => $this->nullable($row['customer_email'] ?? null),
                'notes' => $this->nullable($row['notes'] ?? null),
                'source' => 'import',
            ]
        );

        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function importMembershipCardRow(array $row): bool
    {
        $cardNumber = trim((string) ($row['card_number'] ?? ''));
        $typeId = (int) ($row['membership_card_type_id'] ?? 0);
        if ($cardNumber === '' || $typeId <= 0) {
            return false;
        }

        CustomerMembershipCard::query()->updateOrCreate(
            ['card_number' => $cardNumber],
            [
                'customer_id' => $this->toNullableInt($row['customer_id'] ?? null),
                'membership_card_type_id' => $typeId,
                'nfc_uid' => $this->nullable($row['nfc_uid'] ?? null),
                'status' => trim((string) ($row['status'] ?? 'active')) ?: 'active',
                'issued_at' => $this->nullable($row['issued_at'] ?? null),
                'activated_at' => $this->nullable($row['activated_at'] ?? null),
                'expires_at' => $this->nullable($row['expires_at'] ?? null),
                'assigned_by' => $this->toNullableInt($row['assigned_by'] ?? null),
                'notes' => $this->nullable($row['notes'] ?? null),
            ]
        );

        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function importGiftCardRow(array $row): bool
    {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            return false;
        }

        GiftCard::query()->updateOrCreate(
            ['code' => $code],
            [
                'nfc_uid' => $this->nullable($row['nfc_uid'] ?? null),
                'assigned_customer_id' => $this->toNullableInt($row['assigned_customer_id'] ?? null),
                'initial_value' => $this->toDecimal($row['initial_value'] ?? null),
                'remaining_value' => $this->toDecimal($row['remaining_value'] ?? null),
                'expires_at' => $this->nullable($row['expires_at'] ?? null),
                'status' => trim((string) ($row['status'] ?? 'active')) ?: 'active',
                'issued_by' => $this->toNullableInt($row['issued_by'] ?? null),
                'notes' => $this->nullable($row['notes'] ?? null),
            ]
        );

        return true;
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function toNullableInt(?string $value): ?int
    {
        $trimmed = $this->nullable($value);
        return $trimmed === null ? null : (int) $trimmed;
    }

    private function toDecimal(?string $value): float
    {
        $trimmed = $this->nullable($value);
        if ($trimmed === null) {
            return 0.0;
        }
        $normalized = str_replace([',', ' '], '', $trimmed);
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function toBool(?string $value): bool
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }
}
