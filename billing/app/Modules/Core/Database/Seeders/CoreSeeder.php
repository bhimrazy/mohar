<?php

namespace App\Modules\Core\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Install\DefaultCurrencies;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Demo accounts: admin@example.com (Full Administrator) and demo@example.com with the password "password", plus
 * demo clients. The currencies and system roles come from the same installers `app:install` runs, so the demo
 * and a production install never drift; they are repeated here for `db:seed --class=CoreSeeder`.
 */
class CoreSeeder extends DemoSeeder
{
    public function run(DefaultCurrencies $currencies, SystemRoles $roles): void
    {
        DB::transaction(fn () => $currencies->install());
        DB::transaction(fn () => $roles->install());

        $admin = Role::query()->where('key', SystemRoles::FULL_ADMINISTRATOR)->sole();

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
