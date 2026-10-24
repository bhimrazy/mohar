<?php

use App\Contracts\Orders\OrderNumbers;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\NullOrderNumbers;
use App\Modules\Orders\Models\Order;
use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
});

function serviceWithDocumentsForNumbers(): array
{
    $order = Order::factory()->create(['number' => '4815162342']);
    $service = Service::factory()->create(['order_id' => $order->id]);
    $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'number' => 'INV-1981']);
    $service->invoiceLinks()->create(['invoice_id' => $invoice->id, 'kind' => InvoiceLinkKind::Renewal, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => 1_000, 'status' => InvoiceLinkStatus::Open]);

    return [$service, $invoice];
}

test('the service page names its invoices, order and module-log staff by number and name, not id', function () {
    $staff = actingAsStaff(['services.services.view', 'services.module_log.view']);
    $staff->forceFill(['name' => 'Anita Shrestha'])->save();
    [$service, $invoice] = serviceWithDocumentsForNumbers();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'cpanel', 'command' => 'suspend', 'initiator' => 'staff:'.$staff->id, 'success' => true, 'created_at' => now()]);
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'cpanel', 'command' => 'create', 'initiator' => 'system', 'success' => true, 'created_at' => now()]);

    $this->get(route('admin.services.services.show', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/services/show')
            ->where('invoices.0.invoice_number', 'INV-1981')
            ->where('invoices.0.invoice_url', route('admin.billing.invoices.show', $invoice->id))
            ->where('order.number', '4815162342')
            ->where('moduleLog.0.initiator_label', 'System')
            ->where('moduleLog.1.initiator_label', 'Anita Shrestha'));
});

test('the service page works without the Orders module', function () {
    actingAsStaff(['services.services.view']);
    app()->instance(OrderNumbers::class, new NullOrderNumbers);
    [$service] = serviceWithDocumentsForNumbers();

    $this->get(route('admin.services.services.show', $service))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.number', null));
});

test('a deleted staff member shows as Staff in the module log', function () {
    actingAsStaff(['services.services.view', 'services.module_log.view']);
    $service = Service::factory()->create();
    $gone = User::factory()->create();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'cpanel', 'command' => 'suspend', 'initiator' => 'staff:'.$gone->id, 'success' => true, 'created_at' => now()]);
    $gone->delete();

    $this->get(route('admin.services.services.show', $service))
        ->assertInertia(fn (Assert $page) => $page->where('moduleLog.0.initiator_label', 'Staff'));
});

test('the order page shows the invoice number and the fraud provider name', function () {
    actingAsStaff(['orders.orders.view']);
    $invoice = Invoice::factory()->create(['number' => 'INV-1243']);
    $order = Order::factory()->create(['invoice_id' => $invoice->id, 'fraud_provider' => 'rules']);

    $this->get(route('admin.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/show')
            ->where('invoice.number', 'INV-1243')
            ->where('fraud_provider_label', 'Built-in rules'));
});

test('the servers page opens with capacity and failure cards', function () {
    actingAsStaff(['services.servers.view']);
    $full = Server::factory()->create(['max_accounts' => 10]);
    $roomy = Server::factory()->create(['max_accounts' => 100]);
    Server::factory()->create(['max_accounts' => 50, 'is_active' => false]);
    Service::factory()->count(9)->create(['server_id' => $full->id, 'status' => ServiceStatus::Active]);
    Service::factory()->create(['server_id' => $roomy->id, 'status' => ServiceStatus::Active]);
    ModuleCommand::query()->create(['server_id' => $full->id, 'driver' => 'cpanel', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'created_at' => now()->subHour()]);
    ModuleCommand::query()->create(['server_id' => $full->id, 'driver' => 'cpanel', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'created_at' => now()->subDays(2)]);

    $this->get(route('admin.services.servers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.servers', 3)
            ->where('summary.active', 2)
            ->where('summary.accounts', 10)
            ->where('summary.capacity', 110)
            ->where('summary.near_full', 1)
            ->where('summary.failed_commands', 1)
            ->where('summary.failing_servers', 1)
            ->where('canViewModuleLog', false));
});
