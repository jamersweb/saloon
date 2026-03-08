<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        CustomerLoyaltyAccount::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            ['current_points' => 0]
        );
    }
}

