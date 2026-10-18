<?php

namespace App\Modules\Services\Database\Seeders;

use App\Enums\BillingCycle;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\UsageSnapshot;
use Carbon\CarbonImmutable;

/**
 * Demo data: a server group with two "log" driver servers and services in every status.
 *
 * Product ids 1–4 are fixed demo numbers (no Catalog dependency); product names are stored as the
 * service's product_name snapshot. Lifecycle changes run through the real module command
 * executor so the module log and failed-actions queue have realistic entries.
 */
class ServicesSeeder extends DemoSeeder
{
    /** @var array<int, array{name: string, amount: int}> */
    private const PRODUCTS = [
        1 => ['name' => 'Starter Hosting', 'amount' => 499],
        2 => ['name' => 'Business Hosting', 'amount' => 1299],
        3 => ['name' => 'Reseller Pro', 'amount' => 2999],
        4 => ['name' => 'VPS Small', 'amount' => 1900],
    ];

    public function run(): void
    {
        if (Server::query()->exists()) {
            return;
        }

        $today = CarbonImmutable::today();
        $group = ServerGroup::query()->create(['name' => 'Shared hosting US', 'fill_type' => FillType::LeastFull]);
        $servers = collect([
            ['name' => 'Web 1', 'hostname' => 'web1.demo-host.net', 'ip_address' => '203.0.113.10', 'max_accounts' => 200, 'is_default' => true, 'datacenter' => 'Ashburn'],
            ['name' => 'Web 2', 'hostname' => 'web2.demo-host.net', 'ip_address' => '203.0.113.11', 'max_accounts' => 200, 'is_default' => false, 'datacenter' => 'Ashburn'],
        ])->map(fn (array $attributes): Server => Server::query()->create([
            ...$attributes,
            'driver' => 'log',
            'username' => 'root',
            'password' => 'demo-server-password',
            'secure' => true,
            'is_active' => true,
            'nameservers' => ['ns1.demo-host.net', 'ns2.demo-host.net'],
            'monthly_cost' => 8900,
            'currency' => 'USD',
        ]));
        $servers->each(fn (Server $server, int $index) => $group->servers()->attach($server, ['sort_order' => $index]));
        Server::query()->create([
            'name' => 'VPS cluster', 'hostname' => 'vps.demo-host.net', 'ip_address' => '203.0.113.50', 'driver' => 'log',
            'username' => 'api', 'api_token' => 'demo-token', 'max_accounts' => 0, 'is_active' => true, 'monthly_cost' => 25000, 'currency' => 'USD', 'datacenter' => 'Frankfurt',
        ]);

        $demo = Client::query()->where('email', 'demo@example.com')->first();
        $others = Client::query()->where('email', '!=', 'demo@example.com')->orderBy('id')->limit(8)->get();

        if ($demo !== null) {
            $this->seedDemoClient($demo, $today);
        }

        foreach ($others as $index => $client) {
            $product = ($index % 4) + 1;
            $service = $this->pending($client, $product, BillingCycle::Monthly, strtolower(str_replace(' ', '', $client->last_name)).'-'.($index + 1).'.com', $today->subMonths(($index % 5) + 1)->subDays($index));
            $this->execute($service, ModuleCommandName::Create);
            $this->paidThrough($service, $today->addDays(($index * 5) % 28 + 2));
        }

        $this->seedUsage($today);
    }

    private function seedDemoClient(Client $demo, CarbonImmutable $today): void
    {
        $active = $this->pending($demo, 2, BillingCycle::Monthly, 'demolabs.io', $today->subMonths(5)->subDays(9));
        $this->execute($active, ModuleCommandName::Create);
        $this->paidThrough($active, $today->addDays(12));
        ServiceAddon::query()->create([
            'service_id' => $active->id, 'addon_id' => 1, 'name' => 'Daily backups', 'status' => ServiceStatus::Active,
            'cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'recurring_amount' => 300,
            'registration_date' => $active->registration_date, 'billing_anchor_day' => $active->billing_anchor_day,
            'next_due_date' => $active->next_due_date, 'next_invoice_date' => $active->next_invoice_date, 'activated_at' => $active->activated_at,
        ]);

        $annual = $this->pending($demo, 3, BillingCycle::Annually, 'demolabs-reseller.com', $today->subMonths(7));
        $this->execute($annual, ModuleCommandName::Create);
        $this->paidThrough($annual, $today->subMonths(7)->addYear());

        $overdue = $this->pending($demo, 1, BillingCycle::Monthly, 'old-blog.net', $today->subMonths(3)->subDays(12));
        $this->execute($overdue, ModuleCommandName::Create);
        $this->paidThrough($overdue, $today->subDays(12));
        $this->execute($overdue, ModuleCommandName::Suspend, ['reason' => 'overdue']);

        $this->pending($demo, 4, BillingCycle::Monthly, 'vps.demolabs.io', $today);

        $gone = $this->pending($demo, 1, BillingCycle::Monthly, 'legacy-site.org', $today->subYear());
        $this->execute($gone, ModuleCommandName::Create);
        $this->paidThrough($gone, $today->subMonths(2));
        $this->execute($gone, ModuleCommandName::Terminate);

        $leaving = $this->pending($demo, 1, BillingCycle::Monthly, 'side-project.dev', $today->subMonths(2)->subDays(3));
        $this->execute($leaving, ModuleCommandName::Create);
        $this->paidThrough($leaving, $today->addDays(25));
        $leaving->update(['do_not_renew' => true]);
        $leaving->cancellationRequests()->create([
            'type' => CancellationType::EndOfPeriod, 'reason' => 'Moving this project to a static host.', 'by_staff' => false,
            'requested_by_user_id' => $demo->users()->value('users.id'), 'status' => CancellationStatus::Open, 'process_after' => $leaving->next_due_date,
        ]);

        app(LogDriver::class)->failNext('change_package', message: 'Package "business-plus" does not exist on the server');
        $this->execute($active, ModuleCommandName::ChangePackage, ['previous' => ['product_id' => 1]]);
    }

    private function pending(Client $client, int $product, BillingCycle $cycle, string $domain, CarbonImmutable $registered): Service
    {
        $amount = self::PRODUCTS[$product]['amount'] * ($cycle === BillingCycle::Annually ? 10 : 1);

        $servers = Server::query()->where('hostname', 'like', 'web%')->orderBy('id')->pluck('id');

        return Service::query()->create([
            'server_id' => $servers[Service::query()->count() % max(1, $servers->count())] ?? null,
            'client_id' => $client->id,
            'product_id' => $product,
            'product_name' => self::PRODUCTS[$product]['name'],
            'status' => ServiceStatus::Pending,
            'cycle' => $cycle,
            'currency' => $client->currency,
            'domain' => $domain,
            'first_payment_amount' => $amount,
            'recurring_amount' => $amount,
            'registration_date' => $registered,
            'billing_anchor_day' => $registered->day,
            'next_due_date' => $registered,
            'next_invoice_date' => $cycle->advance($registered, 1, $registered->day),
            'payment_gateway' => 'bank_transfer',
        ]);
    }

    private function paidThrough(Service $service, CarbonImmutable $date): void
    {
        $service->refresh()->update(['next_due_date' => $date, 'next_invoice_date' => $date, 'billing_anchor_day' => $date->day]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function execute(Service $service, ModuleCommandName $command, array $args = []): void
    {
        app(ExecuteModuleCommand::class)->handle($service->id, $command, $args, 'system');
        $service->refresh();
    }

    private function seedUsage(CarbonImmutable $today): void
    {
        Service::query()->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])->orderBy('id')->each(function (Service $service) use ($today): void {
            UsageSnapshot::query()->create([
                'service_id' => $service->id,
                'disk_used_mb' => 300 + ($service->id * 413) % 7000,
                'disk_limit_mb' => 10240,
                'bw_used_mb' => 2000 + ($service->id * 1777) % 60000,
                'bw_limit_mb' => 102400,
                'captured_at' => $today->setTime(4, 0),
            ]);
        });
    }
}
