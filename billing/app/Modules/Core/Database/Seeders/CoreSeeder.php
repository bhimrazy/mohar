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
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'prefix' => '$', 'exchange_rate' => 1, 'is_default' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'exchange_rate' => 0.92]);
        Currency::query()->firstOrCreate(['code' => 'GBP'], ['name' => 'Pound Sterling', 'prefix' => '£', 'exchange_rate' => 0.79]);

        $admin = Role::query()->firstOrCreate(['name' => 'Full Administrator'], ['permissions' => ['*']]);
        Role::query()->firstOrCreate(['name' => 'Billing'], ['permissions' => ['core.clients.*', 'billing.*', 'orders.*', 'reports.*']]);
        Role::query()->firstOrCreate(['name' => 'Support'], ['permissions' => ['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view']]);

        User::query()->firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin',
            'password' => 'password',
            'email_verified_at' => now(),
        ])->forceFill(['is_staff' => true, 'role_id' => $admin->id])->save();

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
