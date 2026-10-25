<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Domain;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * @return array<string, array<string, mixed>> the summary widgets of a client page, keyed by widget key
 */
function clientSummaryWidgets(Client $client): array
{
    $widgets = [];
    test()->get(route('admin.clients.show', $client))->assertOk()
        ->assertInertia(function (Assert $page) use (&$widgets): void {
            $widgets = collect($page->toArray()['props']['summary'])->keyBy('key')->all();
        });

    return $widgets;
}

function clientSummaryStaff(): void
{
    actingAsStaff(['core.clients.view', 'billing.*', 'billing.credit.manage']);
}

function clientSummaryNepalClient(): Client
{
    Currency::factory()->create();
    Currency::factory()->npr()->create();

    return Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']);
}

test('the client page sums outstanding, overdue, credit and paid in the client currency', function () {
    clientSummaryStaff();
    $client = clientSummaryNepalClient();
    issueInvoice($client, [100_000], dueDate: now()->toImmutable()->subDays(5));
    issueInvoice($client, [50_000], dueDate: now()->toImmutable()->addDays(5));
    $paid = issueInvoice($client, [20_000]);
    payInvoice($paid, $paid->total);
    app(CreditLedger::class)->credit($client->id, Money::of(7_500, 'NPR'), 'Goodwill');
    issueInvoice(Client::factory()->create(['currency' => 'NPR']), [999_000], dueDate: now()->toImmutable()->subDay());

    $widgets = clientSummaryWidgets($client);

    expect($widgets['billing.outstanding']['money'])->toBe(['amount' => 150_000, 'currency' => 'NPR'])
        ->and($widgets['billing.overdue']['money']['amount'])->toBe(100_000)
        ->and($widgets['billing.overdue']['tone'])->toBe('danger')
        ->and($widgets['billing.credit']['money'])->toBe(['amount' => 7_500, 'currency' => 'NPR'])
        ->and($widgets['billing.paid']['money']['amount'])->toBe(20_000)
        ->and($widgets['billing.paid']['hint'])->toBe('Net of refunds, excl. account credit')
        ->and($widgets['billing.unpaid']['value'])->toBe(2)
        ->and($widgets['billing.unpaid']['hint'])->toBe('1 overdue')
        ->and($widgets['billing.invoices']['items'])->toHaveCount(3);
});

test('the outstanding figures and their links cover unpaid, collections and payment pending invoices alike', function () {
    clientSummaryStaff();
    $client = clientSummaryNepalClient();
    issueInvoice($client, [10_000]);
    DB::table('invoices')->where('id', issueInvoice($client, [20_000])->id)->update(['status' => 'collections']);
    DB::table('invoices')->where('id', issueInvoice($client, [40_000])->id)->update(['status' => 'payment_pending']);

    $widgets = clientSummaryWidgets($client);

    expect($widgets['billing.outstanding']['money']['amount'])->toBe(70_000)
        ->and($widgets['billing.unpaid']['value'])->toBe(3)
        ->and($widgets['billing.outstanding']['href'])->toBe($widgets['billing.unpaid']['href'])
        ->and($widgets['billing.unpaid']['href'])->toContain('status=outstanding');

    $this->get($widgets['billing.unpaid']['href'])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 3));
});

test('amounts in a currency other than the client currency are named in the hint, not added up', function () {
    clientSummaryStaff();
    $client = clientSummaryNepalClient();
    Currency::factory()->euro()->create();
    issueInvoice($client, [30_000]);
    DB::table('invoices')->where('client_id', $client->id)->update(['currency' => 'EUR']);
    issueInvoice($client, [10_000]);

    $outstanding = clientSummaryWidgets($client)['billing.outstanding'];

    expect($outstanding['money'])->toBe(['amount' => 10_000, 'currency' => 'NPR'])
        ->and($outstanding['hint'])->toContain('€');
});

test('counts and latest rows come from services, domains and tickets, and show no database ids', function () {
    actingAsStaff(['*']);
    $client = clientSummaryNepalClient();
    Service::factory()->count(2)->create(['client_id' => $client->id, 'status' => 'active']);
    Service::factory()->create(['client_id' => $client->id, 'status' => 'suspended']);
    Service::factory()->create(['status' => 'active']);
    Domain::factory()->create(['client_id' => $client->id, 'expiry_date' => today()->addDays(10)]);
    Domain::factory()->create(['client_id' => $client->id]);
    Ticket::factory()->forClient($client)->create();
    Ticket::factory()->forClient($client)->status(TicketStatus::Closed)->create();

    $widgets = clientSummaryWidgets($client);

    expect($widgets['services.count']['value'])->toBe(2)
        ->and($widgets['services.count']['hint'])->toBe('1 suspended')
        ->and($widgets['services.recent']['items'])->toHaveCount(3)
        ->and($widgets['domains.count']['value'])->toBe(2)
        ->and($widgets['domains.count']['hint'])->toBe('1 expiring in 30 days')
        ->and($widgets['support.count']['value'])->toBe(1)
        ->and($widgets['support.recent']['items'])->toHaveCount(1)
        ->and($widgets['support.recent']['items'][0]['subtitle'])->toStartWith('#')
        ->and($widgets['services.recent']['items'][0])->not->toHaveKey('id');
});

test('each section needs its own permission', function () {
    actingAsStaff(['core.clients.view', 'services.services.view']);
    $client = clientSummaryNepalClient();
    issueInvoice($client, [10_000]);

    expect(array_keys(clientSummaryWidgets($client)))->toBe(['services.count', 'services.recent']);
});

test('open tickets are limited to the staff member\'s departments', function () {
    $client = clientSummaryNepalClient();
    $mine = Department::factory()->create();
    $theirs = Department::factory()->create();
    $visible = Ticket::factory()->forClient($client)->create(['department_id' => $mine->id]);
    Ticket::factory()->forClient($client)->create(['department_id' => $theirs->id]);
    $staff = actingAsStaff(['core.clients.view', 'support.tickets.view']);
    DB::table('support_department_user')->insert(['department_id' => $mine->id, 'user_id' => $staff->id]);

    $widgets = clientSummaryWidgets($client);

    expect($widgets['support.count']['value'])->toBe(1)
        ->and($widgets['support.recent']['items'])->toHaveCount(1)
        ->and($widgets['support.recent']['items'][0]['subtitle'])->toBe('#'.$visible->number);
});

test('staff with ticket access but no department see no tickets', function () {
    $client = clientSummaryNepalClient();
    Ticket::factory()->forClient($client)->create();
    actingAsStaff(['core.clients.view', 'support.tickets.view']);

    $widgets = clientSummaryWidgets($client);

    expect($widgets['support.count']['value'])->toBe(0)
        ->and($widgets['support.recent']['items'])->toBe([]);
});

test('a staff member with no module permission gets the page without a summary', function () {
    actingAsStaff(['core.clients.view']);
    $client = clientSummaryNepalClient();
    issueInvoice($client, [10_000]);

    expect(clientSummaryWidgets($client))->toBe([]);
});

test('the client page stays within its query budget however many rows the client has', function () {
    actingAsStaff(['*']);
    $client = clientSummaryNepalClient();
    $count = function () use ($client): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.clients.show', $client))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count(); // warms per-process caches
    $few = $count();
    foreach (range(1, 12) as $i) {
        issueInvoice($client, [1_000 + $i], dueDate: now()->toImmutable()->subDays($i));
    }
    Service::factory()->count(12)->create(['client_id' => $client->id]);
    Domain::factory()->count(12)->create(['client_id' => $client->id]);
    Ticket::factory()->count(12)->forClient($client)->create(['department_id' => Ticket::query()->value('department_id') ?? Department::factory()->create()->id]);
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(60);
});
