<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use App\Services\MembershipCardService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Imports the March–April 2026 membership spreadsheet: customers, contact details, and
 * pre-assigned 16-digit card numbers (digits-only in DB). Safe to re-run: upserts by phone
 * and skips cards that already exist.
 *
 * Rows with uncertain names in the source image are marked in customer notes; edit this file
 * to match your final spreadsheet.
 */
class VinaMembershipRosterSeeder extends Seeder
{
    public function run(): void
    {
        $type = MembershipCardType::query()->where('slug', 'vina-membership-2026')->first();

        if (! $type) {
            $this->command?->warn('Skipping Vina membership roster: run VinaMembershipSeriesSeeder first.');

            return;
        }

        $service = app(MembershipCardService::class);

        foreach ($this->rosterRows() as $index => $row) {
            $seq = $index + 1;
            $digits = $this->cardDigitsForSequence($seq);
            $phone = $this->normalizePhone($row['phone']);

            $customer = Customer::query()->updateOrCreate(
                ['phone' => $phone],
                [
                    'customer_code' => $row['customer_code'],
                    'name' => $row['name'],
                    'email' => $row['email'] !== '' ? $row['email'] : null,
                    'notes' => $row['customer_notes'] !== '' ? $row['customer_notes'] : null,
                    'acquisition_source' => 'vina_membership_roster_2026',
                    'is_active' => true,
                ],
            );

            if (CustomerMembershipCard::query()->where('card_number', $digits)->exists()) {
                continue;
            }

            $issuedAt = Carbon::parse($row['membership_start']);

            $cardNotes = trim(sprintf(
                'Membership ref: %s | Package: %s | Initial purchase: %s',
                $row['membership_ref'],
                $row['package'],
                $row['purchase_amount'],
            ));

            $service->assignCard($customer, $type, null, [
                'card_number' => $digits,
                'issued_at' => $issuedAt,
                'activated_at' => $issuedAt,
                'status' => 'active',
                'notes' => $cardNotes,
            ]);
        }
    }

    /**
     * @return list<array{
     *     membership_ref: string,
     *     customer_code: string,
     *     name: string,
     *     phone: string,
     *     email: string,
     *     package: string,
     *     purchase_amount: string,
     *     membership_start: string,
     *     customer_notes: string,
     * }>
     */
    private function rosterRows(): array
    {
        return [
            [
                'membership_ref' => '26001',
                'customer_code' => 'MEM-26001',
                'name' => 'Fatma Mohebi',
                'phone' => '971506573366',
                'email' => 'fatmamohebi@yahoo.com',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => '',
            ],
            [
                'membership_ref' => '26002',
                'customer_code' => 'MEM-26002',
                'name' => 'Mahtash Sepehrinia',
                'phone' => '971507279552',
                'email' => 'mahtashsn@yahoo.com',
                'package' => 'hair protein',
                'purchase_amount' => '500 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => '',
            ],
            [
                'membership_ref' => '26003',
                'customer_code' => 'MEM-26003',
                'name' => 'Roster member 26003',
                'phone' => '971501260003',
                'email' => '',
                'package' => 'root color',
                'purchase_amount' => '1500 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26004',
                'customer_code' => 'MEM-26004',
                'name' => 'Roster member 26004',
                'phone' => '971501260004',
                'email' => '',
                'package' => 'wash package',
                'purchase_amount' => '500 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26007',
                'customer_code' => 'MEM-26007',
                'name' => 'Roster member 26007',
                'phone' => '971501260007',
                'email' => '',
                'package' => 'eyelash',
                'purchase_amount' => '1000 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26008',
                'customer_code' => 'MEM-26008',
                'name' => 'Pegah Emami-Kalb',
                'phone' => '971507356009',
                'email' => 'emami.pegah@gmail.com',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'WhatsApp: +4917664630476. 10 session approved by Madam.',
            ],
            [
                'membership_ref' => '26009',
                'customer_code' => 'MEM-26009',
                'name' => 'Roster member 26009',
                'phone' => '971501260009',
                'email' => '',
                'package' => 'hair protein',
                'purchase_amount' => '1000 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26010',
                'customer_code' => 'MEM-26010',
                'name' => 'Elnaz Hasanzadeh',
                'phone' => '971501260010',
                'email' => '',
                'package' => 'highlight & protein',
                'purchase_amount' => '1000 AED (500+500)',
                'membership_start' => '2026-03-03',
                'customer_notes' => '',
            ],
            [
                'membership_ref' => '26011',
                'customer_code' => 'MEM-26011',
                'name' => 'Roster member 26011',
                'phone' => '971501260011',
                'email' => '',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26012',
                'customer_code' => 'MEM-26012',
                'name' => 'Roster member 26012',
                'phone' => '971501260012',
                'email' => '',
                'package' => 'root color',
                'purchase_amount' => '1000 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26013',
                'customer_code' => 'MEM-26013',
                'name' => 'Roster member 26013',
                'phone' => '971501260013',
                'email' => '',
                'package' => 'hair protein',
                'purchase_amount' => '500 AED',
                'membership_start' => '2026-03-03',
                'customer_notes' => 'Name not legible in source sheet; update when confirmed.',
            ],
            [
                'membership_ref' => '26014',
                'customer_code' => 'MEM-26014-0012',
                'name' => 'Forough Keshmiri Ebadi',
                'phone' => '971588174848',
                'email' => 'keshmiriforough@gmail.com',
                'package' => 'root color',
                'purchase_amount' => '1000 AED',
                'membership_start' => '2026-03-18',
                'customer_notes' => '',
            ],
            [
                'membership_ref' => '26014',
                'customer_code' => 'MEM-26014-0013',
                'name' => 'Roster member 26014 (card 0013)',
                'phone' => '971501260014',
                'email' => '',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'membership_start' => '2026-03-28',
                'customer_notes' => 'Third line in sheet shared membership id 26014; confirm customer name.',
            ],
            [
                'membership_ref' => '26014',
                'customer_code' => 'MEM-26014-0014',
                'name' => 'Mona Javan',
                'phone' => '971508077326',
                'email' => '',
                'package' => 'Nail refill and Gelish pedicure',
                'purchase_amount' => '1500 AED',
                'membership_start' => '2026-04-04',
                'customer_notes' => '',
            ],
        ];
    }

    private function cardDigitsForSequence(int $sequenceOneToFourteen): string
    {
        $suffix = str_pad((string) $sequenceOneToFourteen, 4, '0', STR_PAD_LEFT);
        $prefix = $sequenceOneToFourteen <= 11
            ? '2602'
            : ($sequenceOneToFourteen <= 13 ? '2603' : '2604');

        return $prefix.'56781000'.$suffix;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
