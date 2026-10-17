<?php

use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Services\CustomFieldValidator;

it('fails a dropdown value that does not match the pattern', function () {
    $field = ProductCustomField::factory()->create(['name' => 'Plan', 'type' => 'dropdown', 'options' => ['abc', 'ABC1'], 'validation_regex' => '/^[a-z]+$/']);

    $errors = app(CustomFieldValidator::class)->validate($field->product_id, [$field->id => 'ABC1']);

    expect($errors)->toBe([$field->id => 'Plan is not in the expected format.']);
});

it('validates required, dropdown and link fields', function () {
    $required = ProductCustomField::factory()->create(['name' => 'Username', 'is_required' => true]);
    $dropdown = ProductCustomField::factory()->create(['product_id' => $required->product_id, 'name' => 'OS', 'type' => 'dropdown', 'options' => ['Linux']]);
    $link = ProductCustomField::factory()->create(['product_id' => $required->product_id, 'name' => 'Site', 'type' => 'link']);
    $checkbox = ProductCustomField::factory()->create(['product_id' => $required->product_id, 'name' => 'Terms', 'type' => 'checkbox', 'is_required' => true]);

    $errors = app(CustomFieldValidator::class)->validate($required->product_id, [$dropdown->id => 'Windows', $link->id => 'not a url', $checkbox->id => '0']);

    expect($errors)->toBe([
        $required->id => 'Enter Username.',
        $dropdown->id => 'Choose one of the listed options for OS.',
        $link->id => 'Enter a valid URL for Site.',
        $checkbox->id => 'Terms must be checked.',
    ]);
});

it('skips staff-only fields for clients but checks them for staff', function () {
    $field = ProductCustomField::factory()->adminOnly()->create(['name' => 'Notes', 'is_required' => true]);

    expect(app(CustomFieldValidator::class)->validate($field->product_id, []))->toBe([])
        ->and(app(CustomFieldValidator::class)->validate($field->product_id, [], asStaff: true))->toHaveKey($field->id);
});

it('accepts valid values', function () {
    $field = ProductCustomField::factory()->create(['validation_regex' => '^\d{3}$', 'is_required' => true]);

    expect(app(CustomFieldValidator::class)->validate($field->product_id, [$field->id => '123']))->toBe([]);
});

it('only accepts http and https links', function (string $url, bool $valid) {
    $field = ProductCustomField::factory()->create(['name' => 'Site', 'type' => 'link']);

    expect(app(CustomFieldValidator::class)->validate($field->product_id, [$field->id => $url]))->toBe($valid ? [] : [$field->id => 'Enter a valid URL for Site.']);
})->with([
    'https' => ['https://example.com', true],
    'http' => ['http://example.com/path', true],
    'javascript' => ['javascript://example.com/%0Aalert(1)', false],
    'ftp' => ['ftp://example.com', false],
]);
