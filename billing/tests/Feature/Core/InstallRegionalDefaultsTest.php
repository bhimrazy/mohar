<?php

use App\Contracts\Install\InstallMarkers;
use App\Modules\Billing\Install\RegionalTaxDefaults;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Install\QueueTimeouts;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Platform\Jobs\RunAutomationJob;
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
    'country' => [['--country' => 'Nepal'], '--country must be a two-letter ISO country code'],
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

test('the shipped database queue configuration passes the check', function () {
    // phpunit runs the sync queue, which the check skips; use the connection production uses, with its shipped retry_after.
    config(['queue.default' => 'database']);
    expect((int) config('queue.connections.database.retry_after'))->toBe(240);

    [$status, $output] = installRegion();

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Queue timeouts \.+ OK/');
});

test('a job declaring a longer timeout than retry_after makes the check fail', function () {
    config(['queue.default' => 'database']);
    $probe = new QueueTimeouts([QueueTimeoutProbeJob::class]);

    expect($probe->longest()['default']['seconds'])->toBe(400)
        ->and($probe->problems())->toHaveCount(1)
        ->and($probe->problems()[0])->toContain('retry_after 240 s, but a job may run for 400 s (QueueTimeoutProbeJob::$timeout)');
});

test('the scan finds the timeouts that real jobs declare', function () {
    $declared = (new QueueTimeouts)->declaredJobTimeouts();

    expect($declared)->toHaveKey(SendEmailMessage::class, 60)
        ->and($declared)->toHaveKey(DeliverWebhook::class, 30)
        ->and($declared)->toHaveKey(RunAutomationJob::class, Queues::AUTOMATION_TIMEOUT_SECONDS);
});

test('a low retry_after stops a first install but only warns on an existing installation', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 120]);

    [$status] = installRegion();
    expect($status)->toBe(1);

    expect(Currency::query()->count())->toBe(0);

    // An installation that already has currencies: this is the re-run after an upgrade.
    Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
    [$status, $output] = installRegion();

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Queue timeouts \.+ WARNING/')
        ->and($output)->toContain('retry_after 120 s');
});

test('a country code that is not an ISO 3166-1 code is refused', function () {
    [$status, $output] = installRegion(['--country' => 'ZZ']);

    expect($status)->toBe(1)
        ->and($output)->toContain('--country must be a two-letter ISO country code')
        ->and(Currency::query()->count())->toBe(0);
});

class QueueTimeoutProbeJob
{
    public int $timeout = 400;
}

test('Core writes the marker under the shared key and Billing follows it', function () {
    [$status] = installRegion(['--country' => 'IN', '--currency' => 'INR', '--timezone' => 'Asia/Kolkata']);
    $settings = app(Settings::class);

    expect($status)->toBe(0)
        ->and($settings->get(InstallMarkers::REGIONAL_COUNTRY))->toBe('IN')
        ->and($settings->get('billing.tax.home_country'))->toBe('IN');
});

test('Billing applies its defaults from the shared marker alone, and ignores an installation without it', function () {
    $settings = app(Settings::class);

    expect(app(RegionalTaxDefaults::class)->install()->unchanged)->toBe(1)
        ->and($settings->all())->not->toHaveKey('billing.tax.home_country');

    $settings->setMany([InstallMarkers::REGIONAL_COUNTRY => 'NP']);
    app(RegionalTaxDefaults::class)->install();

    expect($settings->get('billing.tax.home_country'))->toBe('NP')
        ->and(TaxRule::query()->where('country', 'NP')->count())->toBe(1);
});
