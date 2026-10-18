<?php

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
        ->and(array_keys($sources->for('XYZ')))->toBe([]);
});

test('ECB reference rates are parsed against the euro', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response(rateFixture('ecb-daily.xml'), 200, ['Content-Type' => 'text/xml'])]);

    $snapshot = app(EcbRateSource::class)->fetch();

    expect($snapshot->baseCurrency)->toBe('EUR')
        ->and($snapshot->rates['USD'])->toBe(['units' => '1.1253', 'base' => '1'])
        ->and($snapshot->rates['INR'])->toBe(['units' => '106.8640', 'base' => '1'])
        ->and($snapshot->has('NPR'))->toBeFalse()
        ->and($snapshot->effectiveAt->toDateString())->toBe('2026-10-16')
        ->and($snapshot->crossRate('USD', 'INR'))->toBe('94.964898');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
});

test('Nepal Rastra Bank rates use the latest day and the buy/sell mid per unit', function () {
    Http::fake(['www.nrb.org.np/*' => Http::response(rateFixture('nrb-rates.json'))]);

    $snapshot = app(NrbRateSource::class)->fetch();

    expect($snapshot->baseCurrency)->toBe('NPR')
        ->and($snapshot->effectiveAt->setTimezone('Asia/Kathmandu')->toDateString())->toBe('2026-10-15')
        ->and($snapshot->rates['USD'])->toBe(['units' => '1', 'base' => '152.250000'])
        ->and($snapshot->rates['INR'])->toBe(['units' => '100', 'base' => '160.075000'])
        ->and($snapshot->crossRate('USD', 'NPR'))->toBe('152.250000')
        ->and($snapshot->crossRate('USD', 'INR'))->toBe('95.111666');
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://www.nrb.org.np/api/forex/v1/rates?')
            && $query['page'] === '1' && $query['per_page'] === '10' && isset($query['from'], $query['to']);
    });
});

test('malformed responses raise a readable error', function (string $source, string $body) {
    Http::fake(['*' => Http::response($body)]);

    app($source)->fetch();
})->with([
    'ECB not XML' => [EcbRateSource::class, '<html>maintenance'],
    'ECB without a dated cube' => [EcbRateSource::class, '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube/></gesmes:Envelope>'],
    'NRB empty payload' => [NrbRateSource::class, '{"status":{"code":200},"data":{"payload":[]}}'],
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
