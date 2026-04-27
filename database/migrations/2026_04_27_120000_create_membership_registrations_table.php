<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_membership_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_card_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('registration_date')->nullable();
            $table->string('staff_name')->nullable();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('nationality')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->boolean('is_first_visit')->nullable();
            $table->string('preferred_language')->nullable();
            $table->string('preferred_language_other')->nullable();
            $table->string('heard_about_us')->nullable();
            $table->string('heard_about_us_other')->nullable();
            $table->json('service_interests')->nullable();
            $table->string('service_interests_other')->nullable();
            $table->boolean('requires_home_service')->nullable();
            $table->text('home_service_location')->nullable();
            $table->string('preferred_visit_frequency')->nullable();
            $table->string('spending_profile')->nullable();
            $table->boolean('consent_data_processing')->default(false);
            $table->boolean('consent_marketing')->default(false);
            $table->date('signature_date')->nullable();
            $table->string('signature_name')->nullable();
            $table->string('terms_version')->default('vina-membership-v1');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['membership_card_type_id', 'registration_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_registrations');
    }
};
