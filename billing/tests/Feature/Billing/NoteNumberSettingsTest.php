<?php

use App\Modules\Platform\Services\SettingsCatalog;
use Illuminate\Support\Facades\Validator;

/*
| The note numbering settings refuse the two combinations that make the unique note number collide (vat-register §4.5).
*/

/**
 * @param  array<string, mixed>  $billing
 * @return array<string, mixed> the failing setting keys
 */
function noteSettingErrors(array $billing): array
{
    $validator = Validator::make(['billing' => $billing + [
        'credit_note_format' => 'CN-{NUMBER}', 'credit_note_padding' => 0, 'credit_note_reset' => 'never',
        'debit_note_format' => 'DN-{NUMBER}', 'debit_note_padding' => 0, 'debit_note_reset' => 'never',
    ]], app(SettingsCatalog::class)->rules('billing'));

    return array_filter($validator->errors()->messages(), fn (string $key): bool => str_contains($key, '_note_'), ARRAY_FILTER_USE_KEY);
}

test('the defaults are valid', function () {
    expect(noteSettingErrors([]))->toBe([]);
});

test('credit and debit notes cannot share a format', function () {
    $errors = noteSettingErrors(['credit_note_format' => 'N-{NUMBER}', 'debit_note_format' => 'N-{NUMBER}']);

    expect(array_keys($errors))->toBe(['billing.credit_note_format', 'billing.debit_note_format']);
});

test('formats that differ only in digits are refused, since they can produce the same number (#396 N1)', function () {
    expect(array_keys(noteSettingErrors(['credit_note_format' => 'A{NUMBER}', 'debit_note_format' => 'A1{NUMBER}'])))->toBe(['billing.credit_note_format', 'billing.debit_note_format'])
        ->and(noteSettingErrors(['credit_note_format' => 'CN-{YEAR}-{NUMBER}', 'debit_note_format' => 'CN-{NUMBER}']))->toBe([]);
});

test('a reset needs the period in the format', function (string $reset, string $format, bool $valid) {
    expect(noteSettingErrors(['credit_note_reset' => $reset, 'credit_note_format' => $format]) === [])->toBe($valid);
})->with([
    'never needs nothing' => ['never', 'CN-{NUMBER}', true],
    'yearly without the year' => ['yearly', 'CN-{NUMBER}', false],
    'yearly with the year' => ['yearly', 'CN/{YEAR}/{NUMBER}', true],
    'monthly with the year only' => ['monthly', 'CN/{YEAR}/{NUMBER}', false],
    'monthly with year and month' => ['monthly', 'CN/{YEAR}{MONTH}/{NUMBER}', true],
]);
