<?php

namespace App\Modules\Core\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Role;
use Illuminate\Database\Seeder;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        // Primary currencies: USD (default), INR, NPR. Rates are demo values relative to USD; staff maintain real rates.
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'prefix' => '$', 'exchange_rate' => 1, 'is_default' => true]);
        Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee', 'prefix' => '₹', 'exchange_rate' => 84]);
        Currency::query()->firstOrCreate(['code' => 'NPR'], ['name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => 134.4]);

        $admin = Role::query()->firstOrCreate(['name' => 'Full Administrator'], ['description' => 'Unrestricted access to everything.', 'permissions' => ['*'], 'is_system' => true]);
        Role::query()->firstOrCreate(['name' => 'Billing'], ['description' => 'Clients, orders, invoices and billing reports.', 'permissions' => ['core.clients.*', 'core.contacts.manage', 'billing.*', 'orders.*', 'reports.*'], 'is_system' => true]);
        Role::query()->firstOrCreate(['name' => 'Support'], ['description' => 'Tickets plus read access to clients, services and domains.', 'permissions' => ['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view'], 'is_system' => true]);
        Role::query()->firstOrCreate(['name' => 'Sales'], ['description' => 'Read clients and orders, accept orders, manage quotes.', 'permissions' => ['core.clients.view', 'orders.orders.view', 'orders.orders.accept', 'billing.quotes.view', 'billing.quotes.manage'], 'is_system' => true]);

        User::query()->firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin',
            'password' => 'password',
            'email_verified_at' => now(),
        ])->forceFill(['is_staff' => true, 'role_id' => $admin->id, 'password_changed_at' => now()])->save();

        $demoUser = User::query()->firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Demo Customer',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        if ($demoUser->clients()->doesntExist()) {
            Client::factory()->create(['first_name' => 'Demo', 'last_name' => 'Customer', 'email' => 'demo@example.com', 'company_name' => 'Demo Labs'])
                ->users()->attach($demoUser, ['is_owner' => true]);
            Client::factory()->count(12)->create();
        }
    }
}
