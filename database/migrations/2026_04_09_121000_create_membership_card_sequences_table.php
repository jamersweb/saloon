<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE_FLOOR = 100_000_000_000;

    public function up(): void
    {
        Schema::create('membership_card_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_card_type_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
        });

        $typeIds = DB::table('membership_card_types')->pluck('id');
        $now = now();

        foreach ($typeIds as $typeId) {
            $maxNumeric = self::SEQUENCE_FLOOR;

            $numbers = DB::table('customer_membership_cards')
                ->where('membership_card_type_id', $typeId)
                ->whereNotNull('card_number')
                ->pluck('card_number');

            foreach ($numbers as $raw) {
                $s = (string) $raw;
                if ($s !== '' && ctype_digit($s)) {
                    $maxNumeric = max($maxNumeric, (int) $s);
                }
            }

            $next = max($maxNumeric + 1, self::SEQUENCE_FLOOR + 1);

            DB::table('membership_card_sequences')->insert([
                'membership_card_type_id' => $typeId,
                'next_number' => $next,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_card_sequences');
    }
};
