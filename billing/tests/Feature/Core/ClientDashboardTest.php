<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Billing/helpers.php';

/** Ceiling for one client dashboard visit with every module (measured: 16, the same for 3 and 15 rows of each kind). */
const CLIENT_DASHBOARD_QUERIES = 20;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'Asia/Kathmandu'));
    Currency::factory()->npr()->create();
    [$this->user, $this->client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));
});

/**
 * @return array<string, mixed>
 */
function clientDashboardProps(): array
{
    return test()->get(route('client.dashboard'))->assertOk()->viewData('page')['props'];
}

function panel(string $key): ?array
{
    return collect(clientDashboardProps()['panels'])->firstWhere('key', $key);
}

test('an invoice due soon puts a pay banner with the amount in NPR on top', function () {
    $invoice = issueInvoice($this->client, [12_345_678], dueDate: CarbonImmutable::parse('2026-10-30'));

    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('client/dashboard')
        ->has('notices', 1)
        ->where('notices.0.key', 'billing.due')
        ->where('notices.0.tone', 'warning')
        ->where('notices.0.title', "Invoice {$invoice->number} for Rs 1,23,456.78 is due on Oct 30, 2026")
        ->where('notices.0.actions.1', ['label' => 'Pay Rs 1,23,456.78', 'href' => route('client.invoices.show', $invoice->id), 'primary' => true]));
});

test('an overdue invoice turns the banner to danger, and the earliest due one is the one to pay', function () {
    issueInvoice($this->client, [5_000], dueDate: CarbonImmutable::parse('2026-11-05'));
    $overdue = issueInvoice($this->client, [9_900], dueDate: CarbonImmutable::parse('2026-10-20'));

    $notice = clientDashboardProps()['notices'][0];

    expect($notice['tone'])->toBe('danger')
        ->and($notice['title'])->toBe("Invoice {$overdue->number} for Rs 99.00 was due on Oct 20, 2026")
        ->and($notice['body'])->toBe('You have 1 more invoice to pay.')
        ->and($notice['actions'][0]['href'])->toBe(route('client.invoices.index', ['status' => 'unpaid']));
});

test('no banner when nothing is owed: paid, cancelled and other clients\' invoices do not count', function () {
    payInvoice($paid = issueInvoice($this->client, [4_000]), $paid->total);
    issueInvoice($this->client, [4_000])->forceFill(['status' => 'cancelled'])->save();
    issueInvoice(Client::factory()->create(['currency' => 'NPR']), [4_000], dueDate: CarbonImmutable::parse('2026-10-01'));

    expect(clientDashboardProps()['notices'])->toBe([]);
});

test('the services panel lists the client\'s live services, the ones needing attention first', function () {
    Service::factory()->dueOn('2026-11-01')->create(['client_id' => $this->client->id, 'product_name' => 'Business Hosting', 'domain' => 'himal.com.np', 'currency' => 'NPR', 'recurring_amount' => 299_900]);
    Service::factory()->suspended()->create(['client_id' => $this->client->id, 'product_name' => 'VPS 4 GB']);
    Service::factory()->status(ServiceStatus::Terminated)->create(['client_id' => $this->client->id, 'product_name' => 'Old plan']);
    Service::factory()->create(['product_name' => 'Someone else']);

    $panel = panel('services.list');

    expect(array_column($panel['items'], 'title'))->toBe(['VPS 4 GB', 'Business Hosting'])
        ->and($panel['items'][1])->toMatchArray([
            'subtitle' => 'himal.com.np · Monthly',
            'status' => 'active',
            'money' => ['amount' => 299_900, 'currency' => 'NPR'],
            'date' => '2026-11-01',
            'dateLabel' => 'Renews',
        ])
        ->and($panel['href'])->toBe(route('client.services.index'));
});

test('the invoices panel shows the latest invoices with derived overdue status', function () {
    $paid = issueInvoice($this->client, [1_000], dueDate: CarbonImmutable::parse('2026-10-10'));
    payInvoice($paid, $paid->total);
    $overdue = issueInvoice($this->client, [2_000], dueDate: CarbonImmutable::parse('2026-10-20'));
    issueInvoice($this->client, [3_000], status: 'draft');

    $items = collect(panel('billing.invoices')['items'])->keyBy('href');

    expect($items)->toHaveCount(2)
        ->and($items[route('client.invoices.show', $overdue->id)])->toMatchArray(['status' => 'overdue', 'dateLabel' => 'Due', 'date' => '2026-10-20'])
        ->and($items[route('client.invoices.show', $paid->id)])->toMatchArray(['status' => 'paid', 'dateLabel' => 'Paid', 'date' => '2026-10-23']);
});

test('the support panel puts tickets awaiting the client first and leaves closed ones out', function () {
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Open)->create(['subject' => 'DNS question', 'last_reply_at' => now()]);
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Answered)->create(['subject' => 'Email bouncing', 'last_reply_at' => now()->subDay()]);
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Closed)->create(['subject' => 'Old']);

    $items = panel('support.tickets')['items'];

    expect(array_column($items, 'title'))->toBe(['Email bouncing', 'DNS question'])
        ->and($items[0]['status'])->toBe('awaiting-you');
});

test('the domains panel lists live domains by expiry with auto-renew and an expiring flag', function () {
    Domain::factory()->create(['client_id' => $this->client->id, 'name_unicode' => 'later.com', 'status' => DomainStatus::Active, 'expiry_date' => '2027-03-18', 'auto_renew' => true]);
    Domain::factory()->create(['client_id' => $this->client->id, 'name_unicode' => 'soon.com', 'status' => DomainStatus::Active, 'expiry_date' => '2026-10-29', 'auto_renew' => false]);
    Domain::factory()->status(DomainStatus::Cancelled)->create(['client_id' => $this->client->id, 'name_unicode' => 'gone.com']);

    $items = panel('domains.list')['items'];

    expect(array_column($items, 'title'))->toBe(['soon.com', 'later.com'])
        ->and($items[0])->toMatchArray(['status' => 'expiring', 'subtitle' => 'Auto-renew off', 'dateLabel' => 'Expires', 'date' => '2026-10-29'])
        ->and($items[1])->toMatchArray(['status' => 'active', 'subtitle' => 'Auto-renew on', 'dateLabel' => 'Renews']);
});

test('every panel has an empty state with a next step when the client has nothing yet', function () {
    $panels = collect(clientDashboardProps()['panels'])->keyBy('key');

    expect($panels->keys()->all())->toBe(['services.list', 'billing.invoices', 'support.tickets', 'domains.list'])
        ->and($panels->every(fn (array $panel): bool => $panel['items'] === [] && $panel['empty'] !== ''))->toBeTrue()
        ->and($panels['support.tickets']['emptyAction']['href'])->toBe(route('client.support.tickets.create'))
        ->and($panels['domains.list']['emptyAction']['href'])->toBe(route('domains.search'));
});

test('an account user sees only the areas the owner granted', function () {
    issueInvoice($this->client, [5_000], dueDate: CarbonImmutable::parse('2026-10-20'));
    $contact = User::factory()->create();
    $this->client->users()->attach($contact, ['is_owner' => false, 'permissions' => json_encode(['support'])]);
    $this->actingAs($contact);

    $props = clientDashboardProps();

    expect($props['notices'])->toBe([])
        ->and(array_column($props['panels'], 'key'))->toBe(['support.tickets'])
        ->and(array_column($props['cards'], 'key'))->toBe(['support.open']);
});

test('the dashboard runs a bounded number of queries however many records the client has', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('client.dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $department = Department::factory()->create();
    $seed = function (int $rows) use ($department): void {
        for ($i = 0; $i < $rows; $i++) {
            issueInvoice($this->client, [1_000 + $i], dueDate: CarbonImmutable::parse('2026-10-20')->addDays($i));
            Service::factory()->create(['client_id' => $this->client->id]);
            Ticket::factory()->forClient($this->client)->create(['department_id' => $department->id]);
            Domain::factory()->create(['client_id' => $this->client->id]);
        }
    };

    $seed(3);
    $few = $count();
    $seed(12);
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(CLIENT_DASHBOARD_QUERIES);
});
