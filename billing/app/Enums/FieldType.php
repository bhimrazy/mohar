<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Input type of an integration setting (gateway, provisioning driver, registrar). A secret is a type, not a flag:
 * Secret and SecretText values are stored encrypted, never sent back to the browser, and redacted from logs.
 *
 * Closed enum (ADR 0010): a new case is a major change, because every settings form renders each case.
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Url = 'url';
    case Secret = 'secret';
    case SecretText = 'secret_text';

    public function isSecret(): bool
    {
        return $this === self::Secret || $this === self::SecretText;
    }

    /**
     * Read a type from an array-shaped field. Besides the case values this accepts the older provisioning and
     * registrar spellings (`password`, `checkbox`, `textarea`, `integer`), and a `secret: true` flag turns a
     * plain type into its secret form.
     */
    public static function fromLegacy(string $type, bool $secret = false): self
    {
        $resolved = self::tryFrom($type) ?? match ($type) {
            'password' => self::Secret,
            'checkbox' => self::Boolean,
            'textarea' => self::Text,
            'integer' => self::Number,
            default => throw new InvalidArgumentException("Unknown setting field type [{$type}]."),
        };

        if (! $secret) {
            return $resolved;
        }

        return match ($resolved) {
            self::String, self::Url, self::Number, self::Secret => self::Secret,
            self::Text, self::SecretText => self::SecretText,
            default => throw new InvalidArgumentException("A [{$type}] field cannot be secret."),
        };
    }
}
