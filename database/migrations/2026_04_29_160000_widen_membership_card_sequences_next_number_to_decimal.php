<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('membership_card_sequences')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $table = $connection->getTablePrefix().'membership_card_sequences';

        DB::statement('ALTER TABLE `'.$table.'` MODIFY `next_number` DECIMAL(20,0) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('membership_card_sequences')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $table = $connection->getTablePrefix().'membership_card_sequences';

        DB::statement('ALTER TABLE `'.$table.'` MODIFY `next_number` BIGINT UNSIGNED NOT NULL');
    }
};
