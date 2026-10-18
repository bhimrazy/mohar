<?php

namespace App\Modules\Platform\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the platform admin: staff in each seeded role, client groups, an extra currency,
 * contacts and a second login on the demo account, a pending invitation and some activity.
 */
class PlatformSeeder extends Seeder
{
    public function run(ActivityLogger $activity, Settings $settings): void
    {
        if (app()->environment('local', 'testing')) {
            // The seeded admin has no 2FA yet; enforce it in production only.
            $settings->set('security.staff_2fa_required', false);
        }

        $this->seedHistory();

        $resellers = ClientGroup::query()->firstOrCreate(['name' => 'Resellers'], ['colour' => '#2563eb', 'discount_percent' => 10, 'separate_invoices' => true]);
        ClientGroup::query()->firstOrCreate(['name' => 'VIP'], ['colour' => '#d97706', 'discount_percent' => 5, 'exempt_from_suspension' => true]);

        foreach (['Billing' => ['billing@example.com', 'Bea Billing'], 'Support' => ['support@example.com', 'Sam Support'], 'Sales' => ['sales@example.com', 'Sal Sales']] as $roleName => [$email, $name]) {
            $role = Role::query()->where('name', $roleName)->first();

            if ($role !== null) {
                User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'email_verified_at' => now()])
                    ->forceFill(['is_staff' => true, 'role_id' => $role->id, 'last_login_at' => now()->subDays(rand(0, 9)), 'last_login_ip' => '192.0.2.'.rand(2, 250)])
                    ->save();
            }
        }

        $support = Role::query()->where('name', 'Support')->first();

        if ($support !== null && StaffInvitation::query()->doesntExist()) {
            StaffInvitation::query()->create([
                'email' => 'new.agent@example.com',
                'name' => 'New Agent',
                'role_id' => $support->id,
                'token_hash' => StaffInvitation::hashToken(Str::random(48)),
                'expires_at' => now()->addDays(6),
                'invited_by' => User::query()->where('email', 'admin@example.com')->value('id'),
            ]);
        }

        $demo = Client::query()->where('email', 'demo@example.com')->first();

        if ($demo !== null && $demo->contacts()->doesntExist()) {
            $demo->forceFill(['client_group_id' => $resellers->id])->save();
            Contact::factory()->for($demo)->create(['first_name' => 'Ada', 'last_name' => 'Accounts', 'email' => 'accounts@demolabs.test', 'email_preferences' => ['general' => false, 'invoice' => true, 'support' => false, 'product' => false, 'domain' => false]]);
            Contact::factory()->for($demo)->create(['first_name' => 'Tom', 'last_name' => 'Tech', 'email' => 'tech@demolabs.test']);

            $helper = User::query()->firstOrCreate(['email' => 'helper@demolabs.test'], ['name' => 'Helen Helper', 'password' => 'password', 'email_verified_at' => now()]);
            $demo->users()->syncWithoutDetaching([$helper->id => ['is_owner' => false, 'permissions' => json_encode(['billing', 'support'])]]);

            $admin = User::query()->where('email', 'admin@example.com')->first();
            $activity->log('Client created', $demo, $demo->id, event: 'client.created', actor: $admin);
            $activity->log('Contact accounts@demolabs.test added', $demo, $demo->id, event: 'contact.created', actor: $admin);
            $activity->log('Login helper@demolabs.test added to the account', $helper, $demo->id, ['permissions' => ['billing', 'support']], 'client.user_added', $admin);
            $activity->log('Client group Resellers updated', $resellers, properties: ['discount_percent' => '10.00'], event: 'client_group.updated', actor: $admin);
        }
    }

    /**
     * Demo history for the dashboard: sign-ups spread over the last 12 weeks and 30 days of automation runs.
     */
    private function seedHistory(): void
    {
        // Demo data only: never fabricate history in a live install.
        if (app()->isProduction() || AutomationRun::query()->exists()) {
            return;
        }

        Client::query()->where('email', '!=', 'demo@example.com')->orderBy('id')->get()->each(function (Client $client, int $index): void {
            $client->forceFill(['created_at' => now()->subDays(($index * 7) % 80 + 1)->subHours($index)])->saveQuietly();
        });

        // Demo only (nothing real was executed): 30 days of daily runs for every registered task, with counts in
        // the summaries, one failed run that recovered the next day, and one day the scheduler missed.
        mt_srand(2028);
        $tasks = app(Automation::class)->ordered();

        foreach (range(30, 0) as $daysAgo) {
            $ranAt = today()->subDays($daysAgo)->setTime(3, 0);

            if ($daysAgo === 17 || $ranAt->isFuture()) {
                continue;
            }

            foreach ($tasks as $task => ['stage' => $stage]) {
                $failed = $daysAgo === 9 && $task === array_key_first(array_filter($tasks, fn (array $item): bool => $item['stage'] === Automation::AUTO_CHARGE));
                $run = AutomationRun::query()->create([
                    'run_date' => today()->subDays($daysAgo),
                    'task' => $task,
                    'status' => $failed ? 'failed' : 'success',
                    'summary' => $failed ? 'Gateway timed out after 30 seconds' : (string) ($stage < Automation::SYNC ? mt_rand(0, 14) : mt_rand(0, 3)),
                    'duration_ms' => match (true) {
                        $stage === Automation::RENEWALS => mt_rand(380, 900),
                        $stage === Automation::AUTO_CHARGE => mt_rand(250, 700),
                        $stage >= Automation::SYNC => mt_rand(60, 400),
                        default => mt_rand(20, 160),
                    },
                ]);
                $run->forceFill(['created_at' => $ranAt, 'updated_at' => $ranAt->addMinute()])->save();
            }
        }

        mt_srand();
    }
}
