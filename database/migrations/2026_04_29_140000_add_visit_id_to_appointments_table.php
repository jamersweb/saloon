<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('visit_id')->nullable()->after('customer_package_id');
            $table->index('visit_id');
        });

        DB::table('appointments')
            ->orderBy('id')
            ->select([
                'id',
                'customer_id',
                'customer_name',
                'customer_phone',
                'booked_by',
                'source',
                'created_at',
            ])
            ->chunk(200, function ($appointments): void {
                $visitIdsByGroup = [];

                foreach ($appointments as $appointment) {
                    $createdAt = $appointment->created_at ? \Illuminate\Support\Carbon::parse($appointment->created_at) : null;
                    $groupKey = implode('|', [
                        $appointment->customer_id ?: 'walkin:'.$appointment->customer_name.':'.$appointment->customer_phone,
                        $appointment->booked_by ?: 'none',
                        $appointment->source ?: 'admin',
                        $createdAt?->format('Y-m-d H:i:s') ?: 'no-created-at',
                    ]);

                    $visitId = $visitIdsByGroup[$groupKey] ??= (string) Str::uuid();

                    DB::table('appointments')
                        ->where('id', $appointment->id)
                        ->update(['visit_id' => $visitId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['visit_id']);
            $table->dropColumn('visit_id');
        });
    }
};
