<?php

use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingsRegistry;

/**
 * Security standards §6, made structural (security review 2026-10-19, B1): a registered setting whose key or label
 * reads like a credential is a secret setting, and its section sits behind a sensitive permission. A new one cannot
 * slip through as plain text.
 */
const CREDENTIAL_PATTERN = '/secret|token|password|passwd|passphrase|hash|credential|private.?key|api.?key|\bkey\b|_key\b/i';

/** Settings whose name matches the pattern but hold no credential, and why. */
const NOT_CREDENTIALS = [];

/**
 * @return array<string, array{section: string, permission: ?string, secret: bool}>
 */
function credentialLikeSettings(): array
{
    $found = [];

    foreach (app(SettingsRegistry::class)->sections() as $sectionKey => $section) {
        foreach ($section['settings'] as $definition) {
            if (! array_key_exists($definition->key, NOT_CREDENTIALS) && preg_match(CREDENTIAL_PATTERN, $definition->key.' '.$definition->label) === 1) {
                $found[$definition->key] = ['section' => $sectionKey, 'permission' => $section['permission'], 'secret' => $definition->secret];
            }
        }
    }

    return $found;
}

test('every registered setting that looks like a credential is a secret setting', function () {
    $found = credentialLikeSettings();

    expect($found)->toHaveKey('support.inbound_secret')
        ->and(array_keys(array_filter($found, fn (array $setting): bool => ! $setting['secret'])))->toBe([]);
});

test('every section holding a secret setting is guarded by a sensitive permission', function () {
    $permissions = app(PermissionRegistry::class);
    $unguarded = [];

    foreach (app(SettingsRegistry::class)->sections() as $sectionKey => $section) {
        $holdsSecret = collect($section['settings'])->contains(fn ($definition): bool => $definition->secret);

        if ($holdsSecret && ($section['permission'] === null || ! $permissions->isSensitive($section['permission']))) {
            $unguarded[] = $sectionKey;
        }
    }

    expect($unguarded)->toBe([]);
});

test('the credential pattern catches the usual names and ignores ordinary ones', function (string $name, bool $matches) {
    expect(preg_match(CREDENTIAL_PATTERN, $name) === 1)->toBe($matches);
})->with([
    ['acme.api_key', true],
    ['acme.webhook_secret', true],
    ['acme.access_token', true],
    ['acme.smtp_password', true],
    ['acme.passhash', true],
    ['License key', true],
    ['support.ticket_number_format', false],
    ['domains.keep_dns', false],
    ['Keyboard shortcuts', false],
]);
