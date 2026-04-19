<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 16-digit Vina-style card numbers (e.g. 2602567810000002) exceed MySQL INT / UNSIGNED INT.
     * Ensure next_number is BIGINT UNSIGNED (matches unsignedBigInteger in Laravel).
     */
    public function up(): void
    {
        if (! Schema::hasTable('membership_card_sequences')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE membership_card_sequences MODIFY next_number BIGINT UNSIGNED NOT NULL');
        }
    }

    /**
     * Reverting could corrupt values larger than INT max; leave schema widened in production.
     */
    public function down(): void
    {
        // Intentionally empty: do not shrink next_number after large card numbers exist.
    }
};
