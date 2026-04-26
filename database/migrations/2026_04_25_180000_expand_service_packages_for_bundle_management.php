<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('description');
            $table->unsignedSmallInteger('services_per_visit_limit')->nullable()->after('validity_days');
        });

        DB::table('service_packages')
            ->whereNull('price')
            ->update(['price' => DB::raw('initial_value')]);

        Schema::create('service_package_salon_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salon_service_id')->constrained('salon_services')->cascadeOnDelete();
            $table->unsignedInteger('included_sessions')->default(1);
            $table->timestamps();

            $table->unique(['service_package_id', 'salon_service_id'], 'package_service_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_salon_service');

        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn(['price', 'services_per_visit_limit']);
        });
    }
};
