<?php

use App\Events\Core\ExchangeRatesUpdated;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Actions\Currencies\UpdateExchangeRates;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Platform\FakeRateSource;

beforeEach(function () {
    app(ExchangeRateSources::class)->register(FakeRateSource::class);
    FakeRateSource::quote(['INR' => '85', 'NPR' => '136']);
    Currency::factory()->create();
    Currency::factory()->inr()->create(['rate_source' => 'fake', 'is_auto_rate' => true]);
    Currency::factory()->npr()->create(['rate_source' => 'fake', 'is_auto_rate' => true]);
    Currency::factory()->euro()->create();
});

function rateOf(string $code): string
{
    return (string) Currency::query()->where('code', $code)->sole()->exchange_rate;
}

/**
 * @return array<string, array<string, mixed>>
 */
function rateTodos(): array
{
    return collect(app(Dashboard::class)->resolveTodos('admin', auth()->user()))->keyBy('key')->all();
}

test('the daily update applies rates within the limit, with history, audit and an event', function () {
    Event::fake([ExchangeRatesUpdated::class]);

    $this->artisan('automation:run', ['--only' => 'platform.exchange-rates'])->assertSuccessful();

    expect(rateOf('INR'))->toBe('85.000000')
        ->and(rateOf('NPR'))->toBe('136.000000')
        ->and(rateOf('EUR'))->toBe('0.920000') // manual currencies are never fetched
        ->and(Currency::query()->where('code', 'INR')->sole()->rate_checked_at)->not->toBeNull()
        ->and(ExchangeRateHistory::query()->where('currency', 'INR')->sole())->rate->toBe('85.000000')->source->toBe('fake')->user_id->toBeNull();
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_changed', 'actor_type' => 'system']);
    $entry = DB::table('activity_log')->where('event', 'currency.rate_changed')->orderBy('id')->first();
    expect(json_decode((string) $entry->properties, true))->toBe(['currency' => 'INR', 'old' => '84.000000', 'new' => '85.000000', 'source' => 'fake']);
    Event::assertDispatched(ExchangeRatesUpdated::class, fn (ExchangeRatesUpdated $event): bool => $event->currencies === ['INR', 'NPR'] && $event->source === 'automatic');
    expect(AutomationRun::query()->where('task', 'platform.exchange-rates')->sole())->status->toBe('success')->summary->toBe('2 updated, 0 unchanged, 0 held for review');
});

test('an unchanged rate is recorded as checked without a history row', function () {
    FakeRateSource::quote(['INR' => '84', 'NPR' => '134.4']);

    $result = app(UpdateExchangeRates::class)->handle();

    expect($result['unchanged'])->toBe(['INR', 'NPR'])
        ->and(ExchangeRateHistory::query()->count())->toBe(0);
});

test('a jump over the daily limit is held for approval and shows in Needs attention', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    FakeRateSource::quote(['INR' => '84.5', 'NPR' => '150']); // NPR +11.6%

    $result = app(UpdateExchangeRates::class)->handle();
    app(UpdateExchangeRates::class)->handle(); // the same proposal does not pile up

    expect($result['held'])->toBe(['NPR'])
        ->and($result['updated'])->toBe(['INR'])
        ->and(rateOf('NPR'))->toBe('134.400000')
        ->and(ExchangeRateReview::query()->pending()->sole())
        ->currency->toBe('NPR')->proposed_rate->toBe('150.000000')->change_percent->toBe('11.6071')
        ->and(rateTodos()['platform.exchange_rates.review'])->toMatchArray(['count' => 1, 'tone' => 'warning']);
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_held']);

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page
        ->has('reviews', 1)->where('reviews.0.currency', 'NPR')->where('canManageRates', true));
});

test('the limit is a setting, and 0 turns the check off', function () {
    FakeRateSource::quote(['INR' => '84', 'NPR' => '150']);
    app(Settings::class)->set('platform.rates_max_change_percent', 20);
    expect(app(UpdateExchangeRates::class)->handle()['updated'])->toBe(['NPR']);

    FakeRateSource::quote(['INR' => '84', 'NPR' => '300']);
    app(Settings::class)->set('platform.rates_max_change_percent', 0);
    expect(app(UpdateExchangeRates::class)->handle()['updated'])->toBe(['NPR']);
});

test('staff approve or reject a held change; both are audited', function () {
    $staff = actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Event::fake([ExchangeRatesUpdated::class]);
    FakeRateSource::quote(['INR' => '100', 'NPR' => '150']);
    app(UpdateExchangeRates::class)->handle();
    [$inr, $npr] = [ExchangeRateReview::query()->where('currency', 'INR')->sole(), ExchangeRateReview::query()->where('currency', 'NPR')->sole()];

    $this->post(route('admin.platform.currencies.reviews.approve', $npr))->assertRedirect();
    $this->post(route('admin.platform.currencies.reviews.reject', $inr))->assertRedirect();

    expect(rateOf('NPR'))->toBe('150.000000')
        ->and(rateOf('INR'))->toBe('84.000000')
        ->and($npr->fresh())->status->toBe('approved')->decided_by->toBe($staff->id)
        ->and($inr->fresh()->status)->toBe('rejected')
        ->and(ExchangeRateHistory::query()->where('currency', 'NPR')->sole()->user_id)->toBe($staff->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_review_approved', 'user_id' => $staff->id]);
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_review_rejected', 'user_id' => $staff->id]);
    Event::assertDispatched(ExchangeRatesUpdated::class, fn (ExchangeRatesUpdated $event): bool => $event->currencies === ['NPR'] && $event->source === 'review');

    $this->post(route('admin.platform.currencies.reviews.approve', $npr))->assertSessionHasErrors('review');
});

test('a manual rate change supersedes a pending review', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    FakeRateSource::quote(['INR' => '84', 'NPR' => '150']);
    app(UpdateExchangeRates::class)->handle();
    $npr = Currency::query()->where('code', 'NPR')->sole();

    $this->put(route('admin.platform.currencies.rates.update', $npr), ['rate_source' => 'manual', 'exchange_rate' => '140.25', 'price_rounding' => 'whole', 'price_buffer_percent' => '2.5'])
        ->assertRedirect(route('admin.platform.currencies.edit', $npr));

    expect($npr->fresh())->exchange_rate->toBe('140.250000')->rate_source->toBe('manual')->is_auto_rate->toBeFalse()
        ->price_buffer_percent->toBe('2.50')
        ->and(ExchangeRateReview::query()->sole()->status)->toBe('superseded');
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_settings_updated']);
});

test('rate changes are sensitive: an explicit permission and a recent password are required', function () {
    $npr = Currency::query()->where('code', 'NPR')->sole();
    $payload = ['rate_source' => 'manual', 'exchange_rate' => '1', 'price_rounding' => 'none'];

    actingAsStaff(['core.*']); // wildcards never grant sensitive permissions
    confirmPassword();
    $this->put(route('admin.platform.currencies.rates.update', $npr), $payload)->assertForbidden();
    $this->post(route('admin.platform.currencies.rates.refresh'))->assertForbidden();
    $this->get(route('admin.platform.currencies.conversion'))->assertForbidden();

    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    $this->flushSession();
    $this->put(route('admin.platform.currencies.rates.update', $npr), $payload)->assertSessionHasErrors('password_confirmation');
    $this->post(route('admin.platform.currencies.rates.refresh'))->assertSessionHasErrors('password_confirmation');

    expect(rateOf('NPR'))->toBe('134.400000');
});

test('a source must quote the currency', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    $npr = Currency::query()->where('code', 'NPR')->sole();

    $this->put(route('admin.platform.currencies.rates.update', $npr), ['rate_source' => 'ecb', 'price_rounding' => 'none'])
        ->assertSessionHasErrors(['rate_source' => 'This source does not publish a rate for NPR.']);
    $this->put(route('admin.platform.currencies.rates.update', $npr), ['rate_source' => 'nrb', 'price_rounding' => 'tens'])
        ->assertSessionHasNoErrors();
    expect($npr->fresh())->rate_source->toBe('nrb')->exchange_rate->toBe('134.400000');
});

test('"Update rates now" fetches each source once and reports the outcome', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    FakeRateSource::$fetches = 0;

    $this->post(route('admin.platform.currencies.rates.refresh'))->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Updated: INR, NPR.']);

    expect(FakeRateSource::$fetches)->toBe(1);
});

test('an outage fails the automation task, keeps the rates and is audited; stale rates are flagged', function () {
    actingAsStaff(['core.currencies.manage']);
    FakeRateSource::$down = true;
    Currency::query()->where('rate_source', 'fake')->update(['rate_checked_at' => now()->subDays(5)]);

    $this->artisan('automation:run', ['--only' => 'platform.exchange-rates'])->assertFailed();

    expect(rateOf('INR'))->toBe('84.000000')
        ->and(AutomationRun::query()->where('task', 'platform.exchange-rates')->sole())
        ->status->toBe('failed')->summary->toContain('INR: The test feed could not be reached.')
        ->and(rateTodos()['platform.exchange_rates.stale'])->toMatchArray(['count' => 2]);
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.rate_update_failed']);

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('currencies.2.code', 'INR')->where('currencies.2.is_stale', true)
        ->where('currencies.1.code', 'EUR')->where('currencies.1.is_stale', false));
});

test('manual rates are stale after their own, longer threshold', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::query()->where('rate_source', 'fake')->update(['rate_checked_at' => now()]);
    $eur = Currency::query()->where('code', 'EUR')->sole();
    $eur->forceFill(['rate_updated_at' => now()->subDays(20)])->save();

    expect(rateTodos())->not->toHaveKey('platform.exchange_rates.stale');

    $eur->forceFill(['rate_updated_at' => now()->subDays(31)])->save();
    expect(rateTodos()['platform.exchange_rates.stale']['count'])->toBe(1);

    app(Settings::class)->set('platform.rates_manual_stale_after_days', 60);
    expect(rateTodos())->not->toHaveKey('platform.exchange_rates.stale');

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('manualStaleAfterDays', 60)->where('currencies.1.code', 'EUR')->where('currencies.1.is_stale', false));
});

test('the edit page shows the source options, settings and a daily history series', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    $this->travelTo(now()->subDays(10));
    app(UpdateExchangeRates::class)->handle();
    $this->travelBack();
    FakeRateSource::quote(['INR' => '86', 'NPR' => '136']);
    app(UpdateExchangeRates::class)->handle();
    $inr = Currency::query()->where('code', 'INR')->sole();

    $this->get(route('admin.platform.currencies.edit', $inr))->assertInertia(fn (Assert $page) => $page
        ->component('admin/platform/currencies/form')
        ->where('currency.rate_source', 'fake')
        ->where('sources', fn ($sources) => collect($sources)->pluck('key')->all() === ['ecb', 'nrb', 'fake'])
        ->has('roundings', 4)
        ->has('history', 90)
        ->where('history.89.y', 86)
        ->where('history.70.y', 85));
});
