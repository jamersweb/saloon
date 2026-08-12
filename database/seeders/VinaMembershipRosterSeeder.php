<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Imports the cleaned Golden Members worksheet from:
 * "GOLDEN MEMBERSHIP VINA BEAUTY CENTERCARD LIST (2) (1).xlsx".
 *
 * Safe to re-run: customers are matched by phone/email/code and cards are upserted
 * by their 16-digit card number. Duplicate worksheet rows for the same card are
 * collapsed into one seed row with package history kept in card notes.
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

        foreach ($this->rosterRows() as $row) {
            $phone = $this->normalizePhone($row['phone']);
            $email = trim(strtolower($row['email']));
            $customer = $this->upsertCustomer($row, $phone, $email);

            $issuedAt = Carbon::parse($row['membership_start']);
            $expiresAt = $type->validity_days
                ? $issuedAt->copy()->addDays((int) $type->validity_days)
                : null;

            CustomerMembershipCard::query()->updateOrCreate([
                'card_number' => $row['card_number'],
            ], [
                'customer_id' => $customer->id,
                'membership_card_type_id' => $type->id,
                'issued_at' => $issuedAt,
                'activated_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'status' => $this->normalizeStatus($row['status']),
                'notes' => $this->cardNotes($row),
            ]);
        }
    }

    /**
     * @param  array{
     *     membership_ref: string,
     *     customer_code: string,
     *     name: string,
     *     phone: string,
     *     email: string,
     *     customer_notes: string,
     * }  $row
     */
    private function upsertCustomer(array $row, string $phone, string $email): Customer
    {
        $customer = null;

        if ($phone !== '') {
            $customer = Customer::query()->where('phone', $phone)->first();
        }

        if (! $customer && $email !== '') {
            $customer = Customer::query()->where('email', $email)->first();
        }

        if (! $customer) {
            $customer = Customer::query()->where('customer_code', $row['customer_code'])->first();
        }

        $customer ??= new Customer();

        $customer->fill([
            'customer_code' => $customer->customer_code ?: $row['customer_code'],
            'name' => $row['name'],
            'phone' => $phone !== '' ? $phone : ($customer->phone ?: 'NO-PHONE-'.$row['membership_ref']),
            'email' => $email !== '' ? $email : $customer->email,
            'notes' => $row['customer_notes'] !== '' ? $row['customer_notes'] : $customer->notes,
            'acquisition_source' => 'vina_membership_roster_2026',
            'is_active' => true,
        ]);
        $customer->save();

        return $customer;
    }

    private function cardNotes(array $row): string
    {
        $parts = [
            'Membership ref: '.$row['membership_ref'],
            'Package: '.$row['package'],
            'Initial purchase: '.$row['purchase_amount'],
        ];

        if ($row['package_history'] !== '') {
            $parts[] = 'Package history: '.$row['package_history'];
        }

        if ($row['whatsapp'] !== '') {
            $parts[] = 'WhatsApp: '.$row['whatsapp'];
        }

        if ($row['balance_marker'] !== '') {
            $parts[] = 'Workbook balance marker: '.$row['balance_marker'];
        }

        if ($row['card_notes'] !== '') {
            $parts[] = $row['card_notes'];
        }

        $parts[] = 'Source worksheet row: '.$row['source_row'];

        return implode(' | ', $parts);
    }

    /**
     * @return list<array{
     *     membership_ref: string,
     *     customer_code: string,
     *     name: string,
     *     phone: string,
     *     email: string,
     *     card_number: string,
     *     package: string,
     *     purchase_amount: string,
     *     package_history: string,
     *     membership_start: string,
     *     status: string,
     *     whatsapp: string,
     *     balance_marker: string,
     *     customer_notes: string,
     *     card_notes: string,
     *     source_row: int,
     * }>
     */
    private function rosterRows(): array
    {
        return [
            [
                'membership_ref' => '26001',
                'customer_code' => 'MEM-26001',
                'name' => 'Fatma Mohebi',
                'phone' => '',
                'email' => 'fatemamohebi@yahoo.com',
                'card_number' => '2602567810000001',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '#VALUE!',
                'customer_notes' => 'Phone missing in Golden Members worksheet; seeded with placeholder phone.',
                'card_notes' => '',
                'source_row' => 6,
            ],
            [
                'membership_ref' => '26002',
                'customer_code' => 'MEM-26002',
                'name' => 'Mahtash Sepehrinia',
                'phone' => '971507279552',
                'email' => 'mahtashsn@yahoo.com',
                'card_number' => '2602567810000002',
                'package' => 'hair protein',
                'purchase_amount' => '500 AED',
                'package_history' => 'hair protein / 500 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 7,
            ],
            [
                'membership_ref' => '26003',
                'customer_code' => 'MEM-26003',
                'name' => 'Negin Nordoukhani',
                'phone' => '971509544424',
                'email' => 'negin.ordo@yahoo.com',
                'card_number' => '2602567810000003',
                'package' => 'hair protein',
                'purchase_amount' => '500 AED',
                'package_history' => 'hair protein / 500 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'expired',
                'whatsapp' => '',
                'balance_marker' => '*',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 8,
            ],
            [
                'membership_ref' => '26004',
                'customer_code' => 'MEM-26004',
                'name' => 'Nasrin Alaei',
                'phone' => '971507563975',
                'email' => 'nasrin.alaei2018@yahoo.com',
                'card_number' => '2602567810000004',
                'package' => 'Root color',
                'purchase_amount' => '1500 AED',
                'package_history' => 'Highlight / 500 AED / 2026-03-03; Root color / 1500 AED / 2026-05-18 (10 Session)',
                'membership_start' => '2026-05-18',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '10 Session',
                'source_row' => 24,
            ],
            [
                'membership_ref' => '26007',
                'customer_code' => 'MEM-26007',
                'name' => 'Betina Sepehri',
                'phone' => '971502358000',
                'email' => 'betinasep@yahoo.com',
                'card_number' => '2602567810000005',
                'package' => 'root color',
                'purchase_amount' => '750 AED',
                'package_history' => 'root color / 750 AED / 2026-03-03 (5 session only as per Madam)',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '*',
                'customer_notes' => '',
                'card_notes' => '5 session only as per Madam',
                'source_row' => 10,
            ],
            [
                'membership_ref' => '26008',
                'customer_code' => 'MEM-26008',
                'name' => 'Pegah Emami-Kalb',
                'phone' => '971507356009',
                'email' => 'emami.pegah@gmail.com',
                'card_number' => '2602567810000006',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-03-03 (10 session approved by Madam)',
                'membership_start' => '2026-03-03',
                'status' => 'expired',
                'whatsapp' => '4917664630476',
                'balance_marker' => '*',
                'customer_notes' => '',
                'card_notes' => '10 session approved by Madam',
                'source_row' => 11,
            ],
            [
                'membership_ref' => '26009',
                'customer_code' => 'MEM-26009',
                'name' => 'Maryam Enshaei',
                'phone' => '971508575096',
                'email' => 'maryamenshaei1352@gmail.com',
                'card_number' => '2602567810000007',
                'package' => 'root color',
                'purchase_amount' => '1500 AED',
                'package_history' => 'root color / 1500 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '*',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 12,
            ],
            [
                'membership_ref' => '26010',
                'customer_code' => 'MEM-26010',
                'name' => 'Elnaz Hasanzadeh',
                'phone' => '971581876307',
                'email' => 'ella.vibes2005@gmail.com',
                'card_number' => '2602567810000008',
                'package' => 'highlight + protein',
                'purchase_amount' => '1000 AED (500 + 500)',
                'package_history' => 'highlight / 500 AED / 2026-03-03; protein / 500 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'expired',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 14,
            ],
            [
                'membership_ref' => '26011',
                'customer_code' => 'MEM-26011',
                'name' => 'Mitra Fazeli',
                'phone' => '971504948866',
                'email' => 'mitimalek@yahoo.com',
                'card_number' => '2602567810000009',
                'package' => 'eyelash',
                'purchase_amount' => '1000 AED',
                'package_history' => 'eyelash / 1000 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'expired',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 15,
            ],
            [
                'membership_ref' => '26012',
                'customer_code' => 'MEM-26012',
                'name' => 'Pegah Gold',
                'phone' => '971509264468',
                'email' => '',
                'card_number' => '2602567810000010',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 16,
            ],
            [
                'membership_ref' => '26013',
                'customer_code' => 'MEM-26013',
                'name' => 'Maryam Sadeghi',
                'phone' => '971555014953',
                'email' => '',
                'card_number' => '2602567810000011',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-03-03',
                'membership_start' => '2026-03-03',
                'status' => 'active',
                'whatsapp' => '971507842732',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 17,
            ],
            [
                'membership_ref' => '26014',
                'customer_code' => 'MEM-26014',
                'name' => 'Fourough Keshmiri Ebadi',
                'phone' => '971588174848',
                'email' => 'keshmiriforough@gmail.com',
                'card_number' => '2603567810000012',
                'package' => 'Root color',
                'purchase_amount' => '1000 AED',
                'package_history' => 'Root color / 1000 AED / 2026-03-18',
                'membership_start' => '2026-03-18',
                'status' => 'active',
                'whatsapp' => '588174848',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 18,
            ],
            [
                'membership_ref' => '26015',
                'customer_code' => 'MEM-26015',
                'name' => 'Sima Zoghi',
                'phone' => '971502849886',
                'email' => '',
                'card_number' => '2603567810000013',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-03-28',
                'membership_start' => '2026-03-28',
                'status' => 'expired',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 19,
            ],
            [
                'membership_ref' => '26016',
                'customer_code' => 'MEM-26016',
                'name' => 'Mona Javan',
                'phone' => '971508077326',
                'email' => '',
                'card_number' => '2604567810000014',
                'package' => 'Nail Refil and Gelish Pedicure',
                'purchase_amount' => '1000 AED',
                'package_history' => 'Nail Refil and Gelish Pedicure / 1000 AED / 2026-04-04',
                'membership_start' => '2026-04-04',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 20,
            ],
            [
                'membership_ref' => '26017',
                'customer_code' => 'MEM-26017',
                'name' => 'Mona Javan',
                'phone' => '971508077326',
                'email' => '',
                'card_number' => '2605567810000015',
                'package' => 'Extension Remove and Fix',
                'purchase_amount' => '819 AED',
                'package_history' => 'Extension Remove and Fix / 819 AED / 2026-04-23',
                'membership_start' => '2026-04-23',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 21,
            ],
            [
                'membership_ref' => '26018',
                'customer_code' => 'MEM-26018',
                'name' => 'Nour Baghzouz',
                'phone' => '971561532769',
                'email' => 'masix_houda@hotmail.com',
                'card_number' => '2606567810000016',
                'package' => 'blowdry',
                'purchase_amount' => '800 AED',
                'package_history' => 'blowdry / 800 AED / 2026-04-25',
                'membership_start' => '2026-04-25',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 22,
            ],
            [
                'membership_ref' => '26019',
                'customer_code' => 'MEM-26019',
                'name' => 'Shokofeh Shafeiyan',
                'phone' => '971501493949',
                'email' => 'shokouh.sh@yahoo.com',
                'card_number' => '2607567810000017',
                'package' => 'Root color',
                'purchase_amount' => '1500 AED',
                'package_history' => 'Root color / 1500 AED / 2026-05-01',
                'membership_start' => '2026-05-01',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '',
                'source_row' => 23,
            ],
            [
                'membership_ref' => '26020',
                'customer_code' => 'MEM-26020',
                'name' => 'Makiko Maeda',
                'phone' => '971567052323',
                'email' => 'makikomaeda927@gmail.com',
                'card_number' => '2607567810000018',
                'package' => 'Eyelash + Nail Refil and Gelish Pedicure',
                'purchase_amount' => '2500 AED (1000 + 1500)',
                'package_history' => 'Eyelash / 1000 AED / 2026-06-30; Nail Refil and Gelish Pedicure / 1500 AED / 2026-08-12 (5 for hand refill & 5 gelish pedicure)',
                'membership_start' => '2026-08-12',
                'status' => 'active',
                'whatsapp' => '',
                'balance_marker' => '',
                'customer_notes' => '',
                'card_notes' => '5 for hand refill & 5 gelish pedicure',
                'source_row' => 26,
            ],
        ];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['pending', 'active', 'inactive', 'expired'], true)
            ? $normalized
            : 'active';
    }
}
