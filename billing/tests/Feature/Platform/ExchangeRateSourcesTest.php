<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Drivers\ExchangeRates\EcbRateSource;
use App\Modules\Platform\Drivers\ExchangeRates\NrbRateSource;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Currencies\ExchangeRateUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function rateFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/exchange-rates/{$name}"));
}

test('both central-bank sources are registered and offered only for the currencies they quote', function () {
    $sources = app(ExchangeRateSources::class);

    expect(array_keys($sources->for('INR')))->toBe(['ecb', 'nrb'])
        ->and(array_keys($sources->for('NPR')))->toBe(['nrb'])
        ->and(array_keys($sources->for('XYZ')))->toBe([])
        ->and(array_keys($sources->for('INR', 'NPR')))->toBe(['nrb']); // ECB cannot derive INR through NPR
});

test('ECB reference rates are parsed against the euro', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response(rateFixture('ecb-daily.xml'), 200, ['Content-Type' => 'text/xml'])]);

    $snapshot = app(EcbRateSource::class)->fetch();

    expect($snapshot->baseCurrency)->toBe('EUR')
        ->and($snapshot->rates['USD'])->toBe(['units' => '1.1253', 'base' => '1'])
        ->and($snapshot->rates['INR'])->toBe(['units' => '106.8640', 'base' => '1'])
        ->and($snapshot->fraction('NPR'))->toBeNull()
        ->and($snapshot->effectiveAt->toDateString())->toBe('2026-10-16')
        ->and($snapshot->crossRate('USD', 'INR'))->toBe('94.964898249356');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
});

test('Nepal Rastra Bank rates use the latest day and the buy/sell mid per unit', function () {
    Http::fake(['www.nrb.org.np/*' => Http::response(rateFixture('nrb-rates.json'))]);

    $snapshot = app(NrbRateSource::class)->fetch();

    expect($snapshot->baseCurrency)->toBe('NPR')
        ->and($snapshot->effectiveAt->setTimezone('Asia/Kathmandu')->toDateString())->toBe('2026-10-15')
        ->and($snapshot->rates['USD'])->toBe(['units' => '1', 'base' => '152.250000000000'])
        ->and($snapshot->rates['INR'])->toBe(['units' => '100', 'base' => '160.075000000000'])
        ->and($snapshot->crossRate('USD', 'NPR'))->toBe('152.250000000000')
        ->and($snapshot->crossRate('USD', 'INR'))->toBe('95.111666406372');
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://www.nrb.org.np/api/forex/v1/rates?')
            && $query['page'] === '1' && $query['per_page'] === '10' && isset($query['from'], $query['to']);
    });
});

test('the NRB rate type setting picks the buying, selling or mid rate', function (string $type, string $usd, string $inr, string $phrase) {
    Http::fake(['www.nrb.org.np/*' => Http::response(rateFixture('nrb-rates.json'))]);
    app(Settings::class)->set('platform.nrb_rate_type', $type);

    $snapshot = app(NrbRateSource::class)->fetch();

    expect($snapshot->rates['USD']['base'])->toBe($usd)
        ->and($snapshot->rates['INR']['base'])->toBe($inr)
        ->and(app(NrbRateSource::class)->description())->toContain($phrase);
})->with([
    'mid (default)' => ['mid', '152.250000000000', '160.075000000000', 'the mid rate'],
    'buy' => ['buy', '151.95', '160.00', 'the buying rate'],
    'sell' => ['sell', '152.55', '160.15', 'the selling rate'],
]);

test('malformed responses raise a readable error', function (string $source, string $body) {
    Http::fake(['*' => Http::response($body)]);

    app($source)->fetch();
})->with([
    'ECB not XML' => [EcbRateSource::class, '<html>maintenance'],
    'ECB without a dated cube' => [EcbRateSource::class, '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube/></gesmes:Envelope>'],
    'NRB empty payload' => [NrbRateSource::class, '{"status":{"code":200},"data":{"payload":[]}}'],
    'NRB buy above sell and a negative buy' => [NrbRateSource::class, '{"data":{"payload":[{"date":"2026-10-15","rates":[{"currency":{"iso3":"USD","unit":1},"buy":"160","sell":"150"},{"currency":{"iso3":"INR","unit":100},"buy":"-10","sell":"330"}]}]}}'],
    'NRB HTML error page' => [NrbRateSource::class, '<!doctype html><title>502</title>'],
    'NRB garbage rates' => [NrbRateSource::class, '{"data":{"payload":[{"date":"2026-10-15","rates":[{"currency":{"iso3":"USD","unit":1},"buy":"n/a","sell":""}]}]}}'],
])->throws(ExchangeRateUnavailable::class);

test('outages raise ExchangeRateUnavailable', function (string $source) {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => app($source)->fetch())->toThrow(ExchangeRateUnavailable::class, 'could not be reached');
})->with([EcbRateSource::class, NrbRateSource::class]);

test('HTTP errors raise ExchangeRateUnavailable', function (string $source) {
    Http::fake(['*' => Http::response('busy', 503)]);

    expect(fn () => app($source)->fetch())->toThrow(ExchangeRateUnavailable::class, 'HTTP 503');
})->with([EcbRateSource::class, NrbRateSource::class]);
