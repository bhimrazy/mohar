<?php

use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Queues;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('objects'));

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function installRegion(array $options = []): array
{
    $status = Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test', ...$options]);

    return [$status, Artisan::output()];
}

test('a fresh install defaults to Nepal: country, NPR, Kathmandu time, VAT 13% and a Nepal date and invoice format', function () {
    [$status] = installRegion();
    $settings = app(Settings::class);

    expect($status)->toBe(0)
        ->and($settings->get('core.default_country'))->toBe('NP')
        ->and($settings->get('system.timezone'))->toBe('Asia/Kathmandu')
        ->and($settings->get('core.date_format'))->toBe('j M Y')
        ->and($settings->get('billing.tax.home_country'))->toBe('NP')
        ->and($settings->get('billing.number_format'))->toBe('INV-{NUMBER}')
        ->and($settings->get('billing.tax.enabled'))->toBeFalse()
        ->and(Currency::query()->where('is_default', true)->sole()->only(['code', 'exchange_rate']))->toBe(['code' => 'NPR', 'exchange_rate' => '1.000000000000'])
        ->and(Currency::query()->where('code', 'USD')->value('exchange_rate'))->toBe('0.006581545347');

    $rule = TaxRule::query()->sole();
    expect($rule->only(['name', 'country', 'level', 'rate']))->toBe(['name' => 'VAT', 'country' => 'NP', 'level' => 1, 'rate' => '13.000']);
});

test('the options choose another country, currency and time zone, and every default stays editable', function () {
    [$status] = installRegion(['--country' => 'in', '--currency' => 'usd', '--timezone' => 'Asia/Kolkata']);
    $settings = app(Settings::class);

    expect($status)->toBe(0)
        ->and($settings->get('core.default_country'))->toBe('IN')
        ->and($settings->get('system.timezone'))->toBe('Asia/Kolkata')
        ->and($settings->get('core.date_format'))->toBe('M j, Y')
        ->and($settings->get('billing.tax.home_country'))->toBe('IN')
        ->and(Currency::query()->where('is_default', true)->sole()->code)->toBe('USD')
        ->and(TaxRule::query()->exists())->toBeFalse();

    $registry = app(SettingsRegistry::class);
    expect($registry->definition('system.timezone'))->not->toBeNull()
        ->and($registry->definition('core.default_country'))->not->toBeNull()
        ->and($registry->definition('core.date_format'))->not->toBeNull();
});

test('an invalid country, currency or time zone stops the install before anything is written', function (array $option, string $message) {
    [$status, $output] = installRegion($option);

    expect($status)->toBe(1)
        ->and($output)->toContain($message)
        ->and(Currency::query()->count())->toBe(0);
})->with([
    'country' => [['--country' => 'Nepal'], '--country must be a two-letter code'],
    'currency' => [['--currency' => 'EUR'], '--currency must be one of USD, INR, NPR'],
    'time zone' => [['--timezone' => 'Mars/Olympus'], '--timezone must be a time zone name'],
]);

test('an existing installation keeps what it uses: a later run stores no regional setting and ignores the options', function () {
    Currency::factory()->create(['code' => 'USD', 'is_default' => true]);

    [$status, $output] = installRegion(['--country' => 'NP']);
    $settings = app(Settings::class);

    expect($status)->toBe(0)
        ->and($output)->toContain('already has currencies, so --country, --currency and --timezone were ignored')
        ->and($settings->all())->toBe([])
        ->and($settings->get('core.default_country'))->toBe('US')
        ->and(TaxRule::query()->exists())->toBeFalse();
});

test('settings staff changed after the install are not reset by the next run', function () {
    installRegion();
    $settings = app(Settings::class);
    $settings->setMany(['system.timezone' => 'UTC', 'core.default_country' => 'US', 'billing.tax.home_country' => 'IN']);
    TaxRule::query()->delete();
    Currency::query()->where('code', 'INR')->delete();

    installRegion(['--country' => 'NP']);

    expect($settings->get('system.timezone'))->toBe('UTC')
        ->and($settings->get('core.default_country'))->toBe('US')
        ->and($settings->get('billing.tax.home_country'))->toBe('IN')
        ->and(TaxRule::query()->exists())->toBeFalse();
});

test('the install check fails when retry_after is not above the longest job timeout, and names the fix', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 120]);

    [$status, $output] = installRegion();

    expect($status)->toBe(1)
        ->and($output)->toContain('Queue timeouts')
        ->and($output)->toContain('Connection [database] has retry_after 120 s, but a job may run for 200 s')
        ->and($output)->toContain('Raise retry_after above 200');
});

test('the install check also covers the automation connection and a raised Services timeout cap', function () {
    config(['queue.default' => 'database', 'queue.connections.automation.driver' => 'database', 'queue.connections.automation.retry_after' => Queues::AUTOMATION_TIMEOUT_SECONDS]);

    expect(installRegion()[1])->toContain('Connection [automation] has retry_after 3600 s, but a job may run for 3600 s');

    config(['queue.connections.automation.retry_after' => Queues::AUTOMATION_RETRY_AFTER_SECONDS, 'services.module.max_job_timeout_seconds' => 300]);

    expect(installRegion()[1])->toContain('a job may run for 320 s (services.module.max_job_timeout_seconds plus the lock slack)');
});

test('the shipped queue configuration passes the check', function () {
    [$status, $output] = installRegion();

    expect($status)->toBe(0)
        ->and($output)->toContain('Queue timeouts');
});
