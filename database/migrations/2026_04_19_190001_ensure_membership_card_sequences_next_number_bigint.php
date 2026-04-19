<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotent: widens next_number only if it is not already BIGINT (fixes 16-digit card sequence inserts).
     * Safe when an earlier widen migration already ran; respects DB table prefix.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $prefix = $connection->getTablePrefix();
        $table = $prefix.'membership_card_sequences';

        if (! Schema::hasTable('membership_card_sequences')) {
            return;
        }

        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = $connection->getDatabaseName();

        $column = DB::selectOne(
            'select DATA_TYPE from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$database, $table, 'next_number']
        );

        if ($column && strtolower((string) $column->DATA_TYPE) === 'bigint') {
            return;
        }

        DB::statement('ALTER TABLE `'.$table.'` MODIFY `next_number` BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        //
    }
};
