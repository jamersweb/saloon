<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->decimal('monthly_salary', 12, 2)->nullable()->after('hourly_rate');
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->string('pay_basis', 32)->default('hourly')->after('staff_profile_id');
            $table->decimal('basic_salary', 12, 2)->default(0)->after('hourly_rate');
            $table->decimal('bonus_amount', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('deduction_amount', 12, 2)->default(0)->after('bonus_amount');
            $table->decimal('net_amount', 12, 2)->default(0)->after('deduction_amount');
            $table->string('payment_method', 32)->default('bank_transfer')->after('net_amount');
            $table->timestamp('paid_at')->nullable()->after('payment_method');
            $table->foreignId('finance_expense_entry_id')->nullable()->after('paid_at')->constrained('expense_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_expense_entry_id');
            $table->dropColumn([
                'pay_basis',
                'basic_salary',
                'bonus_amount',
                'deduction_amount',
                'net_amount',
                'payment_method',
                'paid_at',
            ]);
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn('monthly_salary');
        });
    }
};
