<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MembershipCardSequence extends Model
{
    protected $fillable = [
        'membership_card_type_id',
        'next_number',
    ];

    /**
     * Older deployments may still have INT/BIGINT next_number; keep it wide enough for long card ranges.
     * Idempotent widen to DECIMAL(20,0). No-op on sqlite / non-MySQL.
     */
    public static function ensureNextNumberColumnSupportsLongCardNumbers(): void
    {
        if (! Schema::hasTable('membership_card_sequences')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $prefix = $connection->getTablePrefix();
        $table = $prefix.'membership_card_sequences';
        $database = $connection->getDatabaseName();

        $column = DB::selectOne(
            'select DATA_TYPE from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$database, $table, 'next_number']
        );

        if ($column && strtolower((string) $column->DATA_TYPE) === 'decimal') {
            return;
        }

        DB::statement('ALTER TABLE `'.$table.'` MODIFY `next_number` DECIMAL(20,0) NOT NULL');
    }

    protected function casts(): array
    {
        return [
            'next_number' => 'string',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MembershipCardType::class, 'membership_card_type_id');
    }
}
