<?php

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Enums\ReconcileStatus;
use Carbon\CarbonImmutable;

test('withMessage returns a copy with only the message replaced', function () {
    $expiry = CarbonImmutable::parse('2028-01-31');
    $result = new RegistrarResult('pending', $expiry, 'raw text', ['contact_change_pending' => true], ['order_id' => 7]);
    $lookup = new SubmissionLookup(ReconcileStatus::Conflict, $expiry, ['order_id' => 7], 'raw text');

    expect($result->withMessage('masked'))->toEqual(new RegistrarResult('pending', $expiry, 'masked', ['contact_change_pending' => true], ['order_id' => 7]))
        ->and($result->message)->toBe('raw text')
        ->and($lookup->withMessage('masked'))->toEqual(new SubmissionLookup(ReconcileStatus::Conflict, $expiry, ['order_id' => 7], 'masked'))
        ->and($lookup->message)->toBe('raw text');
});

test('a registrar config reports the non-empty values of its secret settings only', function () {
    $config = new RegistrarConfig('acme', ['api_key' => 'k-1', 'sandbox_key' => '', 'reseller_id' => 'R-9'], true, ['api_key', 'sandbox_key', 'missing']);

    expect($config->secrets())->toBe(['k-1'])
        ->and($config->setting('reseller_id'))->toBe('R-9')
        ->and($config->setting('missing', 'default'))->toBe('default');
});
