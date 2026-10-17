<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Core\Models\Client;
use App\Support\Automation\Automation;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('every billing permission used by the module is registered', function () {
    $registry = app(PermissionRegistry::class);
    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Billing')));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            preg_match_all("/'(billing\.[a-z_]+\.(?:view|manage))'/", (string) file_get_contents($file->getPathname()), $matches);
            $used = [...$used, ...$matches[1]];
        }
    }

    expect($used)->not->toBeEmpty();
    foreach (array_unique($used) as $permission) {
        expect($registry->exists($permission))->toBeTrue("{$permission} is not registered");
    }
});

test('every billing config default has a setting definition', function () {
    $keys = collect(app(SettingsRegistry::class)->sections()['billing']['settings'])->map(fn (SettingDefinition $definition) => $definition->key);
    $defaults = collect(Arr::dot(config('billing')))->keys()
        ->map(fn (string $key) => 'billing.'.preg_replace('/\.\d+$/', '', $key))->unique();

    expect($defaults->diff($keys)->values()->all())->toBe([]);
});

test('billing automation tasks are registered in pipeline order', function () {
    $tasks = array_keys(array_filter(app(Automation::class)->ordered(), fn ($task, $key) => str_starts_with($key, 'billing.'), ARRAY_FILTER_USE_BOTH));

    expect($tasks)->toBe(['billing.late_fees', 'billing.auto_capture', 'billing.reminders', 'billing.auto_cancel', 'billing.housekeeping']);
});

test('navigation shows billing items according to permissions', function () {
    $staff = actingAsStaff(['billing.invoices.view']);
    $groups = collect(app(Navigation::class)->forUser('admin', $staff));
    $billing = collect($groups->firstWhere('title', 'Billing')['items'] ?? [])->pluck('title');

    expect($billing->all())->toBe(['Invoices'])
        ->and(collect(app(Navigation::class)->clientLinks(5, $staff))->pluck('href')->first())->toEndWith('/admin/billing/invoices?client=5');

    [$user] = actingAsClient();
    $client = collect(collect(app(Navigation::class)->forUser('client', $user))->firstWhere('title', 'Billing')['items'])->pluck('title');
    expect($client->all())->toBe(['Invoices', 'Quotes', 'Credit & funds', 'Payment methods']);
});

test('dashboards show billing cards', function () {
    $staff = actingAsStaff(['billing.*']);
    billingClient();
    Invoice::factory()->overdue()->create();

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'billing.overdue')['value'] === 1
            && collect($cards)->firstWhere('key', 'billing.unpaid')['money']['currency'] === 'USD'));

    $client = Client::factory()->withOwner()->create();
    $this->actingAs($client->users()->firstOrFail());
    issueInvoice($client, [4_200]);

    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'billing.unpaid')['money']['amount'] === 4_200
            && collect($cards)->contains('key', 'billing.credit')));
});

test('global search finds invoices by number for permitted staff', function () {
    $staff = actingAsStaff(['billing.invoices.view']);
    $invoice = Invoice::factory()->create(['number' => 'INV-77731']);

    $results = collect(app(GlobalSearch::class)->search('77731', $staff))->firstWhere('group', 'Invoices');

    expect($results['results'][0]['title'])->toBe('INV-77731')
        ->and($results['results'][0]['href'])->toBe(route('admin.billing.invoices.show', $invoice))
        ->and(app(GlobalSearch::class)->search('77731', actingAsStaff(['support.*'])))->toBe([]);
});

test('merge fields resolve invoices, quotes and transactions with the client id', function () {
    $invoice = issueInvoice(billingClient(), [1_234]);
    $transaction = payInvoice($invoice, 1_234);
    $quote = Quote::factory()->create();
    $registry = app(MergeFieldRegistry::class);

    expect($registry->resolve('invoice', $invoice->id))->toMatchArray(['client_id' => $invoice->client_id, 'number' => $invoice->number, 'total' => '12.34 USD'])
        ->and($registry->resolve('quote', $quote->id))->toHaveKey('client_id', $quote->client_id)
        ->and($registry->resolve('transaction', $transaction->id))->toMatchArray(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id]);
});
