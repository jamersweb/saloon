<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_membership_cards', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('customer_membership_cards', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_membership_cards', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('customer_membership_cards', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete();
        });
    }
};
