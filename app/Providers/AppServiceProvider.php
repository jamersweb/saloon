<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\ExpenseEntry;
use App\Models\FinanceSetting;
use App\Models\PayrollLine;
use App\Models\TaxInvoice;
use App\Observers\CustomerObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Customer::observe(CustomerObserver::class);

        Route::bind('invoice', function (string $value) {
            return TaxInvoice::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('expense', function (string $value) {
            return ExpenseEntry::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('line', function (string $value) {
            return PayrollLine::query()->whereKey($value)->firstOrFail();
        });

        RateLimiter::for('whatsapp-outbound', function () {
            $settings = FinanceSetting::current();
            $limit = (int) ($settings->whatsapp_rate_limit_per_minute ?: config('services.whatsapp.rate_limit_per_minute', 60));

            return Limit::perMinute(max(1, $limit))->by('whatsapp-outbound');
        });
    }
}
