<?php

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    Currency::factory()->inr()->create();
    $this->usd = Client::factory()->create(['currency' => 'USD']);
    $this->inr = Client::factory()->create(['currency' => 'INR', 'country' => 'IN']);
});

/**
 * @return Collection<string, array<string, mixed>>
 */
function billingWidgets(?array $permissions = null): Collection
{
    return collect(app(Dashboard::class)->resolveWidgets('admin', $permissions === null ? billingStaff() : actingAsStaff($permissions)))->keyBy('key');
}

test('income consolidates every currency at today\'s rates and offers each currency unconverted', function () {
    payInvoice(issueInvoice($this->usd, [10_000]), 10_000);
    payInvoice(issueInvoice($this->inr, [840_000]), 840_000); // ₹8,400 at 84 per dollar = $100

    $card = billingWidgets()['billing.income'];

    expect($card['money'])->toBe(['amount' => 20_000, 'currency' => 'USD'])
        ->and($card['value'])->toBe('$200.00')
        ->and($card['variants']['USD']['money'])->toBe(['amount' => 10_000, 'currency' => 'USD'])
        ->and($card['variants']['INR']['money'])->toBe(['amount' => 840_000, 'currency' => 'INR'])
        ->and($card['variants']['INR']['value'])->toBe('₹8,400.00')
        ->and($card['sparkline'])->toHaveCount(12)
        ->and(collect($card['sparkline'])->last())->toBe(20_000)
        ->and($card['hint'])->toBe('Oct 1–18 vs. Sep 1–18');
});

test('the income chart compares the last 12 months with the same period a year earlier', function () {
    $this->travelTo('2025-09-10 10:00:00');
    payInvoice(issueInvoice($this->usd, [5_000]), 5_000);
    $this->travelTo('2026-10-18 12:00:00');
    payInvoice(issueInvoice($this->usd, [10_000]), 10_000);

    $chart = collect(app(Dashboard::class)->resolveWidgets('admin', billingStaff(), deferred: true))->firstWhere('key', 'billing.income_chart');
    [$current, $previous] = $chart['series'];

    expect(array_column($current['points'], 'x'))->toHaveCount(12)
        ->and($current['points'][0]['x'])->toBe('2025-11-01')
        ->and($current['points'][11])->toBe(['x' => '2026-10-01', 'y' => 10_000])
        ->and($previous['compare'])->toBeTrue()
        ->and($previous['points'][10])->toBe(['x' => '2026-09-01', 'y' => 5_000])
        ->and($chart['summary']['money'])->toBe(['amount' => 10_000, 'currency' => 'USD'])
        ->and($chart['summary']['trend'])->toMatchArray(['value' => '100%', 'direction' => 'up', 'label' => 'vs. year before'])
        ->and($chart['ranges']['30d']['summary']['trend'])->toBeNull();
});

test('outstanding and overdue are money at risk, with the oldest overdue invoice in the hint', function () {
    issueInvoice($this->usd, [3_000], dueDate: now()->toImmutable()->addDays(5));
    issueInvoice($this->usd, [2_000], dueDate: now()->toImmutable()->subDays(12));
    issueInvoice($this->inr, [168_000], dueDate: now()->toImmutable()->subDays(3)); // $20

    $widgets = billingWidgets();
    $overdue = $widgets['billing.overdue'];
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.overdue');

    expect($widgets['billing.unpaid']['money'])->toBe(['amount' => 7_000, 'currency' => 'USD'])
        ->and($widgets['billing.unpaid']['hint'])->toBe('3 unpaid invoices')
        ->and($overdue['money'])->toBe(['amount' => 4_000, 'currency' => 'USD'])
        ->and($overdue['count'])->toBe(2)
        ->and($overdue['tone'])->toBe('danger')
        ->and($overdue['hint'])->toBe('2 invoices, oldest 12 days')
        ->and($overdue['variants']['INR']['money'])->toBe(['amount' => 168_000, 'currency' => 'INR'])
        ->and($overdue['variants']['INR']['hint'])->toBe('1 invoice, oldest 3 days')
        ->and(collect($overdue['sparkline'])->last())->toBe(4_000)
        ->and($todo)->toMatchArray(['count' => 2, 'icon' => 'Receipt', 'hint' => '$20.00 + ₹1,680.00 at risk']);
});

test('income by currency shows each currency as received, its converted share, and tax per currency', function () {
    payInvoice(issueInvoice($this->usd, [30_000]), 30_000);
    payInvoice(issueInvoice($this->inr, [840_000]), 840_000);
    Invoice::factory()->paid()->create(['client_id' => $this->inr->id, 'currency' => 'INR', 'paid_at' => now(), 'tax1_name' => 'CGST', 'tax1_amount' => 7_200, 'tax2_name' => 'SGST', 'tax2_amount' => 7_200]);
    Invoice::factory()->paid()->create(['client_id' => $this->inr->id, 'currency' => 'INR', 'paid_at' => now()->subMonths(2), 'tax1_name' => 'CGST', 'tax1_amount' => 99_999]);

    $split = collect(app(Dashboard::class)->resolveWidgets('admin', billingStaff(), deferred: true))->firstWhere('key', 'billing.currency_split');

    expect($split['type'])->toBe('breakdown')
        ->and($split['rows'])->toHaveCount(2)
        ->and($split['rows'][0])->toMatchArray(['code' => 'USD', 'money' => ['amount' => 30_000, 'currency' => 'USD'], 'share' => 75])
        ->and($split['rows'][1])->toMatchArray(['code' => 'INR', 'label' => 'Indian Rupee', 'money' => ['amount' => 840_000, 'currency' => 'INR'], 'share' => 25])
        ->and($split['notes'])->toBe([
            ['label' => 'CGST', 'money' => ['amount' => 7_200, 'currency' => 'INR']],
            ['label' => 'SGST', 'money' => ['amount' => 7_200, 'currency' => 'INR']],
        ]);
});

test('billing widgets follow their permissions', function () {
    $invoicesOnly = billingWidgets(['billing.invoices.view'])->keys();

    expect($invoicesOnly)->toContain('billing.unpaid', 'billing.overdue')
        ->not->toContain('billing.income', 'billing.income_chart', 'billing.currency_split', 'billing.recent_payments')
        ->and(billingWidgets(['support.*'])->keys()->filter(fn (string $key): bool => str_starts_with($key, 'billing.')))->toBeEmpty()
        ->and(collect(app(Dashboard::class)->resolveHealth('admin', actingAsStaff(['billing.invoices.view'])))->pluck('key'))->not->toContain('billing.gateways');
});

test('the gateway health check flags payments waiting for review', function () {
    $staff = billingStaff();
    $attempt = fn (AttemptStatus $status, array $extra = []) => PaymentAttempt::query()->forceCreate([
        'reference' => 'pa_'.Str::random(12), 'gateway' => 'sandbox_card', 'client_id' => $this->usd->id,
        'amount' => 1_000, 'currency' => 'USD', 'document_amount' => 1_000, 'document_currency' => 'USD', 'status' => $status, ...$extra,
    ]);
    $attempt(AttemptStatus::Succeeded);
    $attempt(AttemptStatus::Succeeded);
    $attempt(AttemptStatus::Failed);

    $check = fn () => collect(app(Dashboard::class)->resolveHealth('admin', $staff))->firstWhere('key', 'billing.gateways');

    expect($check())->toMatchArray(['value' => '66.7%', 'tone' => 'warning', 'detail' => '2 of 3 online payments succeeded (7 days)']);

    $attempt(AttemptStatus::Ambiguous, ['updated_at' => now()->subHours(2)]);

    expect($check())->toMatchArray(['value' => '1 to review', 'tone' => 'danger']);
});

test('money widgets carry a variant for each configured currency, empty ones included', function () {
    payInvoice(issueInvoice($this->inr, [840_000]), 840_000);

    $widgets = billingWidgets();

    expect(array_keys($widgets['billing.income']['variants']))->toBe(['USD', 'INR'])
        ->and($widgets['billing.income']['variants']['USD']['money'])->toBe(['amount' => 0, 'currency' => 'USD'])
        ->and($widgets['billing.unpaid']['variants']['USD'])->toMatchArray(['money' => ['amount' => 0, 'currency' => 'USD'], 'hint' => 'No unpaid invoices'])
        ->and($widgets['billing.overdue']['variants']['INR']['hint'])->toBe('Nothing overdue');
});
