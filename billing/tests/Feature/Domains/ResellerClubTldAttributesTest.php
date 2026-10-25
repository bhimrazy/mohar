<?php

use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\TldAttributes;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Validation\ValidationException;

test('ResellerClub declares fields for the extensions it needs them for, and none for the rest', function () {
    $driver = app(ResellerClubDriver::class);
    $keys = fn (string $tld): array => array_map(fn ($field) => $field->key, $driver->tldAttributeFields($tld));

    expect($driver)->toBeInstanceOf(DeclaresTldAttributes::class)
        ->and(app(RegistrarDriverRegistry::class)->supports('resellerclub', 'tld_attributes'))->toBeTrue()
        ->and($keys('.us'))->toBe(['purpose', 'category'])
        ->and($keys('.US'))->toBe(['purpose', 'category'])
        ->and($keys('.com.au'))->toBe(['id-type', 'id', 'policyReason', 'isAUWarranty'])
        ->and($keys('.com'))->toBe([])
        ->and($keys('.np'))->toBe([]);
});

test('every ResellerClub field is a plain, required, non-secret input', function () {
    $driver = app(ResellerClubDriver::class);

    foreach (['.us', '.com.au'] as $tld) {
        foreach ($driver->tldAttributeFields($tld) as $field) {
            expect($field->required)->toBeTrue()->and($field->isSecret())->toBeFalse();
        }
    }
});

test('ResellerClub explains who qualifies in the help text and checks an ABN or ACN for its length', function () {
    $driver = app(ResellerClubDriver::class);
    $us = $driver->tldAttributeFields('.us');
    $au = $driver->tldAttributeFields('.com.au');

    expect($us[1]->help)->toContain('connection to the United States')
        ->and($au[0]->help)->toContain('Australian businesses')
        ->and($au[3]->type->value)->toBe('boolean')
        ->and($driver->validateTldAttributes('.com.au', ['id-type' => 'ABN', 'id' => '51 824 753 556']))->toBe([])
        ->and($driver->validateTldAttributes('.com.au', ['id-type' => 'ABN', 'id' => '1234']))->toHaveKey('id')
        ->and($driver->validateTldAttributes('.com.au', ['id-type' => 'ACN', 'id' => '004 085 616']))->toBe([])
        ->and($driver->validateTldAttributes('.com.au', ['id-type' => 'ACN', 'id' => '51824753556']))->toHaveKey('id')
        ->and($driver->validateTldAttributes('.com.au', ['id-type' => 'TM', 'id' => 'anything']))->toBe([])
        ->and($driver->validateTldAttributes('.us', ['purpose' => 'P1']))->toBe([]);
});

test('the ABN check runs with the generic checks, so every problem shows at once', function () {
    RegistrarAccount::query()->create(['key' => 'resellerclub', 'is_active' => true, 'test_mode' => false, 'settings' => []]);
    Tld::factory()->extension('.com.au')->priced()->create(['registrar_key' => 'resellerclub']);
    $errorsFor = function (array $input): array {
        try {
            app(TldAttributes::class)->clean('.com.au', $input);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    };

    $empty = $errorsFor([]);
    $short = $errorsFor(['id-type' => 'ABN', 'id' => '1234', 'policyReason' => '', 'isAUWarranty' => '1']);

    expect(array_keys($empty))->toBe(['tld_attributes.id-type', 'tld_attributes.id', 'tld_attributes.policyReason', 'tld_attributes.isAUWarranty'])
        ->and($empty['tld_attributes.id'][0])->toBe('Enter identification number.')
        ->and(array_keys($short))->toBe(['tld_attributes.policyReason', 'tld_attributes.id'])
        ->and($short['tld_attributes.id'][0])->toBe('Enter the 11-digit ABN of the business.')
        ->and($errorsFor(['id-type' => 'ABN', 'id' => '51 824 753 556', 'policyReason' => '1', 'isAUWarranty' => '1']))->toBe([]);
});
