<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrmAutomationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_automation_customer_filters_apply_to_paginated_results(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $vipTag = CustomerTag::create([
            'name' => 'VIP',
            'color' => '#111827',
            'is_active' => true,
        ]);

        $matching = Customer::create([
            'customer_code' => 'CUST-ALI-001',
            'name' => 'Aliya Noor',
            'phone' => '5551001001',
            'email' => 'aliya@example.com',
            'is_active' => true,
        ]);
        $matching->tags()->attach($vipTag->id);

        Customer::create([
            'customer_code' => 'CUST-AZAR-002',
            'name' => 'Azar Joon',
            'phone' => '5551001002',
            'email' => 'azar@example.com',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('customers.automation.index', [
                'search' => 'Aliya',
                'tag_id' => $vipTag->id,
                'tag_state' => 'tagged',
                'active_status' => 'active',
                'sort' => 'name_asc',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Automation')
                ->where('customerFilters.search', 'Aliya')
                ->where('customerFilters.tag_id', $vipTag->id)
                ->where('customerFilters.tag_state', 'tagged')
                ->where('customerFilters.active_status', 'active')
                ->where('customerFilters.per_page', 10)
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $matching->id)
                ->where('customers.data.0.name', 'Aliya Noor'));
    }

    public function test_crm_automation_customer_list_paginates(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        foreach (range(1, 26) as $index) {
            Customer::create([
                'customer_code' => sprintf('CUST-%03d', $index),
                'name' => sprintf('Customer %02d', $index),
                'phone' => sprintf('555200%04d', $index),
                'email' => sprintf('customer%02d@example.com', $index),
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->get(route('customers.automation.index', [
                'page' => 2,
                'per_page' => 10,
                'sort' => 'name_asc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Automation')
                ->where('customers.current_page', 2)
                ->where('customers.per_page', 10)
                ->where('customers.total', 26)
                ->has('customers.data', 10)
                ->where('customers.data.0.name', 'Customer 11'));
    }

    public function test_crm_automation_contact_filters_apply_to_paginated_results(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $vipTag = CustomerTag::create([
            'name' => 'Returning',
            'color' => '#2563eb',
            'is_active' => true,
        ]);

        $readyContact = Customer::create([
            'customer_code' => 'CUST-CON-001',
            'name' => 'Fatma Mohebi',
            'phone' => '971505673366',
            'email' => 'fatma@example.com',
            'is_active' => true,
        ]);
        $readyContact->tags()->attach($vipTag->id);

        Customer::create([
            'customer_code' => 'CUST-CON-002',
            'name' => 'Inactive Contact',
            'phone' => '',
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('customers.automation.index', [
                'contact_search' => 'Fatma',
                'contact_tag_id' => $vipTag->id,
                'contact_tag_state' => 'tagged',
                'contact_active_status' => 'active',
                'contact_per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Automation')
                ->where('contactFilters.search', 'Fatma')
                ->where('contactFilters.tag_id', $vipTag->id)
                ->where('contactFilters.tag_state', 'tagged')
                ->where('contactFilters.active_status', 'active')
                ->where('contacts.total', 1)
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $readyContact->id)
                ->where('contacts.data.0.whatsapp_ready', true));
    }
}
