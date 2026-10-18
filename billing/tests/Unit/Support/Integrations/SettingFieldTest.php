<?php

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;

test('gateway-shaped fields are read with their secret types and value => label options', function () {
    $secret = SettingField::fromArray(['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret', 'required' => true]);
    $pem = SettingField::fromArray(['key' => 'private_key', 'label' => 'Private key', 'type' => 'secret_text']);
    $mode = SettingField::fromArray(['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['inline' => 'Inline', 'hosted' => 'Hosted'], 'default' => 'inline']);

    expect($secret->type)->toBe(FieldType::Secret)
        ->and($secret->isSecret())->toBeTrue()
        ->and($secret->required)->toBeTrue()
        ->and($pem->type)->toBe(FieldType::SecretText)
        ->and($mode->options)->toBe(['inline' => 'Inline', 'hosted' => 'Hosted'])
        ->and($mode->default)->toBe('inline')
        ->and($mode->isSecret())->toBeFalse();
});

test('provisioning-shaped fields map password and the secret flag onto secret types', function () {
    expect(SettingField::fromArray(['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true])->type)->toBe(FieldType::Secret)
        ->and(SettingField::fromArray(['key' => 'token', 'label' => 'Token', 'type' => 'text', 'required' => false, 'secret' => true])->type)->toBe(FieldType::SecretText)
        ->and(SettingField::fromArray(['key' => 'enabled', 'label' => 'Enabled', 'type' => 'checkbox'])->type)->toBe(FieldType::Boolean)
        ->and(SettingField::fromArray(['key' => 'fail', 'label' => 'Fail', 'type' => 'select', 'options' => ['', 'create']])->options)->toBe(['' => '', 'create' => 'create']);
});

test('an unknown type, or a secret flag on a type that cannot hold a secret, is refused', function () {
    expect(fn () => SettingField::fromArray(['key' => 'x', 'label' => 'X', 'type' => 'colour']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingField::fromArray(['key' => 'x', 'label' => 'X', 'type' => 'boolean', 'secret' => true]))->toThrow(InvalidArgumentException::class);
});

test('a select can take its options from the remote system', function () {
    $field = SettingField::fromArray(['key' => 'package', 'label' => 'Package', 'type' => 'select', 'options_source' => 'remote:packages']);

    expect($field->optionsSource)->toBe('remote:packages')
        ->and($field->toArray())->toMatchArray(['options_source' => 'remote:packages', 'options' => []]);
});
