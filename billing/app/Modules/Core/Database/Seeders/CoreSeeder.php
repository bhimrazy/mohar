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

        // System roles are kept in sync on every seed (explicit lists; sensitive permissions are never wildcard-granted).
        $roles = [
            'Full Administrator' => ['Unrestricted access to everything.', ['*']],
            'Billing' => ['Clients, orders, invoices and billing reports.', ['core.clients.view', 'core.clients.create', 'core.clients.update', 'core.contacts.manage', 'billing.*', 'orders.*', 'reports.*']],
            'Support' => ['Tickets plus read access to clients, services and domains.', ['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view']],
            'Sales' => ['Read clients and orders, accept orders, manage quotes.', ['core.clients.view', 'orders.orders.view', 'orders.orders.accept', 'billing.quotes.view', 'billing.quotes.manage']],
        ];

        foreach ($roles as $name => [$description, $permissions]) {
            Role::query()->updateOrCreate(['name' => $name], ['description' => $description, 'permissions' => $permissions, 'is_system' => true]);
        }

        $admin = Role::query()->where('name', 'Full Administrator')->sole();

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
