<?php

use App\Modules\Domains\Support\DomainName;
use App\Modules\Domains\Support\Nameservers;

test('lookup input is reduced to a bare lowercase host', function (string $input, string $expected) {
    expect(DomainName::toAscii($input))->toBe($expected);
})->with([
    'scheme, www and path' => ['HTTPS://WWW.Example.COM/path', 'example.com'],
    'query and fragment' => ['example.com?x=1#top', 'example.com'],
    'port' => ['http://example.co.uk:8080/', 'example.co.uk'],
    'trailing dot and spaces' => ['  Example.org.  ', 'example.org'],
    'bare label' => ['MyBrand', 'mybrand'],
]);

test('internationalised names are stored as punycode and shown in unicode', function () {
    expect(DomainName::toAscii('Bücher.de'))->toBe('xn--bcher-kva.de')
        ->and(DomainName::toUnicode('xn--bcher-kva.de'))->toBe('bücher.de')
        ->and(DomainName::toUnicode('example.com'))->toBe('example.com');
});

test('internationalised names are rejected when IDN is disabled', function () {
    DomainName::toAscii('bücher.de', allowIdn: false);
})->throws(InvalidArgumentException::class, 'Internationalised domain names are not supported.');

test('invalid names are rejected', function (string $input) {
    expect(DomainName::isValid($input))->toBeFalse();
})->with([
    'empty' => [''],
    'leading hyphen' => ['-example.com'],
    'trailing hyphen' => ['example-.com'],
    'underscore' => ['ex_ample.com'],
    'empty label' => ['example..com'],
    'reserved hyphens' => ['ab--cd.com'],
    'label over 63' => [str_repeat('a', 64).'.com'],
    'name over 253' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.com'],
]);

test('labels at the length limits are accepted', function () {
    expect(DomainName::isValid(str_repeat('a', 63).'.com'))->toBeTrue()
        ->and(DomainName::isValid('a.com'))->toBeTrue()
        ->and(DomainName::isValid('xn--bcher-kva.de'))->toBeTrue();
});

test('nameservers are normalised, de-duplicated and need 2 to 5 hostnames', function () {
    $clean = Nameservers::normalise([' NS1.Example.com. ', 'ns1.example.com', 'ns2.example.com', '']);

    expect($clean)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and(Nameservers::isValidSet($clean))->toBeTrue()
        ->and(Nameservers::isValidSet(['ns1.example.com']))->toBeFalse()
        ->and(Nameservers::isValidSet(['ns1', 'ns2.example.com']))->toBeFalse()
        ->and(Nameservers::isValidSet(array_map(fn ($i) => "ns{$i}.example.com", range(1, 6))))->toBeFalse();
});
