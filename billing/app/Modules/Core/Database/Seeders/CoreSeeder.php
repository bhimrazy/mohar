<?php

namespace App\Modules\Core\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Install\DefaultCurrencies;
use App\Modules\Core\Install\RegionalDefaults;
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
        app(RegionalDefaults::class)->choose(RegionalDefaults::COUNTRY, RegionalDefaults::CURRENCY, RegionalDefaults::TIMEZONE);
        DB::transaction(fn () => $currencies->install());
        DB::transaction(fn () => $roles->install());

        // A clearly fictional host, so the demo's public site, mail and invoices speak for a host, never for the
        // product or a real company. Only empty settings are filled: re-seeding keeps a developer's own values.
        $this->fillEmptySettings([
            'core.company_name' => 'Example Hosting',
            'core.company_email' => 'billing@example.com',
            'core.support_email' => 'support@example.com',
            'core.default_country' => 'NP',
            'system.timezone' => RegionalDefaults::TIMEZONE,
            'core.date_format' => RegionalDefaults::DATE_FORMAT,
            'core.support_hours' => 'Sunday–Friday, 9:00–18:00 (Nepal time). Tickets every day.',
            'core.whatsapp' => '+977 9800000000',
            'core.registration_number' => '123456/078/079',
            'core.social_facebook' => 'https://example.com/facebook',
            'core.social_instagram' => 'https://example.com/instagram',
            'core.social_linkedin' => 'https://example.com/linkedin',
        ]);

        $admin = Role::query()->where('key', SystemRoles::FULL_ADMINISTRATOR)->sole();

        User::query()->firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin',
            'password' => 'password',
            'email_verified_at' => now(),
        ])->forceFill(['is_staff' => true, 'role_id' => $admin->id, 'password_changed_at' => now()])->save();

        $demoUser = User::query()->firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Sujata Shrestha',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        if ($demoUser->clients()->doesntExist()) {
            // The showcase client is a Lalitpur company paying in NPR with 13% VAT; the one US client comes last, so
            // multi-currency shows without leading the demo.
            Client::factory()->nepal()->create([
                'first_name' => 'Sujata', 'last_name' => 'Shrestha', 'email' => 'demo@example.com', 'company_name' => 'Himal Labs Pvt. Ltd.',
                'phone' => '+977 9812345678', 'address_1' => 'Pulchowk Road 14', 'city' => 'Lalitpur', 'state' => 'Bagmati', 'postcode' => '44700',
                'tax_id' => '305412876',
            ])->users()->attach($demoUser, ['is_owner' => true]);
            Client::factory()->nepal()->count(11)->create();
            Client::factory()->create([
                'first_name' => 'Olivia', 'last_name' => 'Bennett', 'company_name' => 'Northwind Labs', 'email' => 'olivia.bennett@example.com',
                'address_1' => '100 Market Street', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001',
            ]);
        }
    }
}
