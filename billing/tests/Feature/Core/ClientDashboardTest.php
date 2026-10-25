<?php

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * Ceiling for one client dashboard visit with every module, the same for 3 and 15 rows of each kind. Measured 24 with
 * VAT on and only grace rows listed (each converting its fee): 19 before Renew now's fixes, plus one query each for the
 * open renewal invoices, the client and the tax rules (one tax calculation per visit) and the default and domain
 * currencies (one for all rows), plus the settings reload the seeding causes.
 */
const CLIENT_DASHBOARD_QUERIES = 24;

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

function dashboardPanel(string $key): ?array
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

test('the banner and the Unpaid card count the same set: collections yes, payment_pending no', function () {
    issueInvoice($this->client, [7_000], dueDate: CarbonImmutable::parse('2026-10-10'))->forceFill(['status' => 'payment_pending'])->save();
    $collections = issueInvoice($this->client, [3_000], dueDate: CarbonImmutable::parse('2026-10-15'));
    $collections->forceFill(['status' => 'collections'])->save();
    issueInvoice($this->client, [2_000], dueDate: CarbonImmutable::parse('2026-10-28'));

    $props = clientDashboardProps();
    $unpaid = collect($props['cards'])->firstWhere('key', 'billing.unpaid');

    expect($props['notices'][0]['title'])->toStartWith("Invoice {$collections->number} for Rs 30.00 was due")
        ->and($props['notices'][0]['body'])->toBe('You have 1 more invoice to pay.')
        ->and($unpaid['hint'])->toBe('2 to pay')
        ->and($unpaid['money']['amount'])->toBe(5_000);
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
    Service::factory()->pending()->create(['client_id' => $this->client->id, 'product_name' => 'Email Pro']);
    Service::factory()->status(ServiceStatus::Terminated)->create(['client_id' => $this->client->id, 'product_name' => 'Old plan']);
    Service::factory()->create(['product_name' => 'Someone else']);

    $panel = dashboardPanel('services.list');

    expect(array_column($panel['items'], 'title'))->toBe(['VPS 4 GB', 'Email Pro', 'Business Hosting'])
        ->and($panel['items'][1]['date'])->toBeNull()
        ->and($panel['items'][2])->toMatchArray([
            'subtitle' => 'himal.com.np · Monthly',
            'status' => 'active',
            'money' => ['amount' => 299_900, 'currency' => 'NPR'],
            'date' => '2026-11-01',
            'dateLabel' => 'Renews',
        ])
        ->and($panel['href'])->toBe(route('client.services.index'));
});

test('the invoices panel shows the latest invoices with derived overdue status and Pay on the ones to pay', function () {
    $paid = issueInvoice($this->client, [1_000], dueDate: CarbonImmutable::parse('2026-10-10'));
    payInvoice($paid, $paid->total);
    $overdue = issueInvoice($this->client, [2_000], dueDate: CarbonImmutable::parse('2026-10-20'));
    issueInvoice($this->client, [3_000], status: 'draft');

    $items = collect(dashboardPanel('billing.invoices')['items'])->keyBy('href');

    expect($items)->toHaveCount(2)
        ->and($items[route('client.invoices.show', $overdue->id)])->toMatchArray([
            'status' => 'overdue', 'dateLabel' => 'Due', 'date' => '2026-10-20',
            'action' => ['label' => 'Pay', 'href' => route('client.invoices.show', $overdue->id)],
        ])
        ->and($items[route('client.invoices.show', $paid->id)])->toMatchArray(['status' => 'paid', 'dateLabel' => 'Paid', 'date' => '2026-10-23', 'action' => null]);
});

test('the support panel puts tickets awaiting the client first and leaves closed ones out', function () {
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Open)->create(['subject' => 'DNS question', 'last_reply_at' => now()]);
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Answered)->create(['subject' => 'Email bouncing', 'last_reply_at' => now()->subDay()]);
    Ticket::factory()->forClient($this->client)->status(TicketStatus::CustomerReply)->create(['subject' => 'Still slow', 'last_reply_at' => now()->subHour()]);
    Ticket::factory()->forClient($this->client)->status(TicketStatus::Closed)->create(['subject' => 'Old']);

    $items = dashboardPanel('support.tickets')['items'];

    expect(array_column($items, 'title'))->toBe(['Email bouncing', 'DNS question', 'Still slow'])
        ->and(array_column($items, 'status'))->toBe(['awaiting-you', 'open', 'waiting-for-us']);
});

test('the domains panel lists live domains by expiry with auto-renew and an expiring flag', function () {
    Domain::factory()->create(['client_id' => $this->client->id, 'name_unicode' => 'later.com', 'status' => DomainStatus::Active, 'expiry_date' => '2027-03-18', 'auto_renew' => true]);
    Domain::factory()->create(['client_id' => $this->client->id, 'name_unicode' => 'soon.com', 'status' => DomainStatus::Active, 'expiry_date' => '2026-10-29', 'auto_renew' => false]);
    Domain::factory()->create(['client_id' => $this->client->id, 'name_unicode' => 'lapsed.com', 'status' => DomainStatus::Grace, 'expiry_date' => '2026-10-15', 'auto_renew' => true]);
    Domain::factory()->status(DomainStatus::Cancelled)->create(['client_id' => $this->client->id, 'name_unicode' => 'gone.com']);

    $items = dashboardPanel('domains.list')['items'];

    expect(array_column($items, 'title'))->toBe(['lapsed.com', 'soon.com', 'later.com'])
        ->and($items[0])->toMatchArray(['status' => 'grace', 'dateLabel' => 'Expired'])
        ->and($items[1])->toMatchArray(['status' => 'expiring', 'subtitle' => null, 'dateLabel' => 'Expires', 'date' => '2026-10-29'])
        ->and($items[1]['toggle'])->toMatchArray(['on' => false])
        ->and($items[2])->toMatchArray(['status' => 'active', 'subtitle' => null, 'dateLabel' => 'Renews'])
        ->and($items[2]['toggle'])->toMatchArray(['on' => true]);
});

/** A client domain on a .com priced in NPR (renewal Rs 1,500.00 a year). */
function p8bDashboardDomain(Client $client, string $name, string $expiry, array $attributes = []): Domain
{
    $extension = substr($name, (int) strpos($name, '.'));
    $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced('NPR', renew: 150_000)->create();

    return Domain::factory()->named(substr($name, 0, (int) strpos($name, '.')), $tld)->expiring($expiry)
        ->create(['client_id' => $client->id, 'currency' => 'NPR', 'recurring_amount' => 150_000, ...$attributes]);
}

test('domain rows offer the auto-renew switch, and Renew now with its term and price only when renewal is due', function () {
    $soon = p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10', ['auto_renew' => false]);
    $lapsed = p8bDashboardDomain($this->client, 'lapsed.com', '2026-10-15', ['status' => DomainStatus::Grace]);
    $later = p8bDashboardDomain($this->client, 'later.com', '2027-06-01');

    $items = collect(dashboardPanel('domains.list')['items'])->keyBy('title');

    expect($items['asha-traders.com']['toggle'])->toBe([
        'label' => 'Auto-renew asha-traders.com',
        'text' => 'Auto-renew',
        'href' => route('client.domains.auto-renew', $soon->id),
        'field' => 'enabled',
        'on' => false,
    ])
        ->and($items['asha-traders.com']['action'])->toBe([
            'label' => 'Renew now',
            'href' => route('client.domains.renew', $soon->id),
            'method' => 'post',
            'confirm' => [
                'title' => 'Renew asha-traders.com for 1 year?',
                'description' => 'We create an invoice for Rs 1,500.00 (1 year) and take you to it. The domain renews when the invoice is paid.',
                'confirmLabel' => 'Create renewal invoice',
                'data' => ['years' => 1],
            ],
        ])
        ->and($items['lapsed.com']['action']['href'])->toBe(route('client.domains.renew', $lapsed->id))
        ->and($items['later.com']['toggle']['on'])->toBeTrue()
        ->and($items['later.com']['action'])->toBeNull();

    // Confirming posts the term from the payload: the renewal invoice is created for it.
    $this->post($items['asha-traders.com']['action']['href'], $items['asha-traders.com']['action']['confirm']['data'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(app('session.store')->get('inertia.flash_data.toast.message'))->toStartWith('Renewal invoice');
});

test('Renew now on a redemption domain states the term plus the fee and how VAT applies, matching the invoice', function (string $mode, string $taxPhrase) {
    TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
    billingSettings(['tax.enabled' => true, 'tax.mode' => $mode, 'tax.apply_to' => ['service', 'domain']]);
    $tld = Tld::factory()->extension('.com')->priced('NPR', renew: 150_000)->create(['redemption_fee' => 8_000]);
    Domain::factory()->named('restore', $tld)->expiring('2026-09-01')
        ->create(['client_id' => $this->client->id, 'currency' => 'NPR', 'recurring_amount' => 150_000, 'status' => DomainStatus::Redemption]);

    $action = collect(dashboardPanel('domains.list')['items'])->firstWhere('title', 'restore.com')['action'];

    $this->post($action['href'], $action['confirm']['data'])->assertSessionHasNoErrors();
    $invoice = Invoice::query()->where('client_id', $this->client->id)->sole();
    $tax = $invoice->tax1_amount + $invoice->tax2_amount;
    // The dialog states the price lines: the invoice total when tax is included, the subtotal when it is added.
    $stated = app(MoneyFormatter::class)->format(Money::of($mode === 'inclusive' ? $invoice->total : $invoice->subtotal, 'NPR'));

    // The fee is stored in the default currency and converted, as on the invoice.
    expect($tax)->toBeGreaterThan(0)
        ->and($invoice->total)->toBe($invoice->subtotal + $tax)
        ->and($action['confirm']['description'])->toBe("We create an invoice for {$stated} (1 year plus the redemption fee){$taxPhrase} and take you to it. The domain renews when the invoice is paid.");
})->with([
    'tax added' => ['exclusive', ' plus VAT'],
    'tax included' => ['inclusive', ', VAT included'],
]);

test('Renew now says nothing about tax when none applies', function () {
    p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10');

    expect(collect(dashboardPanel('domains.list')['items'])->firstWhere('title', 'asha-traders.com')['action']['confirm']['description'])
        ->toBe('We create an invoice for Rs 1,500.00 (1 year) and take you to it. The domain renews when the invoice is paid.');
});

test('a domain whose renewal invoice is already open offers Pay invoice instead of Renew now', function () {
    $domain = p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10');
    $this->post(route('client.domains.renew', $domain->id), ['years' => 1])->assertSessionHasNoErrors();
    $invoice = Invoice::query()->where('client_id', $this->client->id)->sole();

    expect(collect(dashboardPanel('domains.list')['items'])->firstWhere('title', 'asha-traders.com')['action'])
        ->toBe(['label' => 'Pay invoice', 'href' => route('client.invoices.show', $invoice->id)]);
});

test('Renew now is throttled per client', function () {
    $domain = p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10');

    foreach (range(1, 10) as $attempt) {
        $this->post(route('client.domains.renew', $domain->id), ['years' => 1])->assertRedirect();
    }

    $this->post(route('client.domains.renew', $domain->id), ['years' => 1])->assertTooManyRequests();
    expect(Invoice::query()->where('client_id', $this->client->id)->count())->toBe(1);
});

test('no domain controls for names registered by the client, domains not open to changes, or with renewal orders off', function () {
    $selfRegistered = Tld::factory()->extension('.np')->create(['registered_by_client' => true]);
    Domain::factory()->named('asha', $selfRegistered)->create(['client_id' => $this->client->id, 'expiry_date' => null]);
    p8bDashboardDomain($this->client, 'pending.com', '2026-11-01', ['status' => DomainStatus::PendingTransfer]);
    p8bDashboardDomain($this->client, 'soon.com', '2026-11-01');

    $items = collect(dashboardPanel('domains.list')['items'])->keyBy('title');

    expect($items['asha.np']['toggle'])->toBeNull()->and($items['asha.np']['action'])->toBeNull()
        ->and($items['pending.com']['toggle'])->toBeNull()->and($items['pending.com']['action'])->toBeNull()
        ->and($items['soon.com']['action'])->not->toBeNull();

    app(Settings::class)->set('domains.enable_renewal_orders', false);

    expect(collect(dashboardPanel('domains.list')['items'])->firstWhere('title', 'soon.com'))
        ->toMatchArray(['action' => null])->toHaveKey('toggle.on', true);
});

test('auto-renew toggled on the dashboard returns there; elsewhere or from another host it goes to the domain page', function () {
    $domain = p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10', ['auto_renew' => false]);

    $this->from(route('client.dashboard'))->put(route('client.domains.auto-renew', $domain->id), ['enabled' => true])
        ->assertRedirect(route('client.dashboard'))->assertSessionHas('inertia.flash_data.toast.message', 'Auto-renew is on.');
    expect($domain->fresh()->auto_renew)->toBeTrue();

    $this->from(route('client.domains.show', $domain->id))->put(route('client.domains.auto-renew', $domain->id), ['enabled' => false])
        ->assertRedirect(route('client.domains.show', $domain->id));
    $this->from('https://evil.example/phish')->put(route('client.domains.auto-renew', $domain->id), ['enabled' => true])
        ->assertRedirect(route('client.domains.show', $domain->id));
    // Same host for parse_url, another host for a browser; and another scheme or port is another origin.
    foreach (['http://evil.example\\@localhost/dashboard', 'https://localhost/dashboard', 'http://localhost:8080/dashboard'] as $referer) {
        $this->from($referer)->put(route('client.domains.auto-renew', $domain->id), ['enabled' => true])
            ->assertRedirect(route('client.domains.show', $domain->id));
    }
});

test('the support panel adds New ticket and Help articles to its header only when it lists tickets', function () {
    expect(dashboardPanel('support.tickets')['actions'])->toBe([]);

    Ticket::factory()->forClient($this->client)->status(TicketStatus::Open)->create(['subject' => 'DNS question']);

    expect(dashboardPanel('support.tickets')['actions'])->toBe([
        ['label' => 'New ticket', 'href' => route('client.support.tickets.create'), 'icon' => 'Plus'],
        ['label' => 'Help articles', 'href' => route('support.kb.index'), 'icon' => 'BookOpen'],
    ]);
});

test('every panel has an empty state with a next step when the client has nothing yet', function () {
    $panels = collect(clientDashboardProps()['panels'])->keyBy('key');

    expect($panels->keys()->all())->toBe(['services.list', 'billing.invoices', 'support.tickets', 'domains.list'])
        ->and($panels->every(fn (array $panel): bool => $panel['items'] === [] && $panel['empty'] !== ''))->toBeTrue()
        ->and($panels->map(fn (array $panel): string => $panel['emptyAction']['href'])->all())->toBe([
            'services.list' => route('store.index'),
            'billing.invoices' => route('store.index'),
            'support.tickets' => route('client.support.tickets.create'),
            'domains.list' => route('store.domains'),
        ]);
});

test('an account user sees only the areas the owner granted', function () {
    issueInvoice($this->client, [5_000], dueDate: CarbonImmutable::parse('2026-10-20'));
    p8bDashboardDomain($this->client, 'asha-traders.com', '2026-11-10');
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
        // Each real request starts with empty request-scoped memos (the TLD price cache).
        app()->forgetScopedInstances();
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
            // Due for renewal on a different extension each, so every listed row prices Renew now from its own TLD.
            p8bDashboardDomain($this->client, "renew-{$rows}-{$i}".['.com', '.net', '.org', '.io', '.dev'][$i % 5], '2026-11-01');
            // And lapsed, so each row also converts its grace fee from the default currency.
            p8bDashboardDomain($this->client, "lapsed-{$rows}-{$i}.com", '2026-10-01', ['status' => DomainStatus::Grace]);
        }
        Tld::query()->where('extension', '.com')->update(['grace_fee' => 2_000]);
        TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
        billingSettings(['tax.enabled' => true, 'tax.apply_to' => ['service', 'domain']]);
    };

    $seed(3);
    $few = $count();
    $seed(12);
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(CLIENT_DASHBOARD_QUERIES);
});
