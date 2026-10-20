<?php

use App\Support\Integrations\Redactor;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingsRegistry;

/**
 * Security standards §6, made structural (security review 2026-10-19, B1): a registered setting whose key or label
 * reads like a credential is a secret setting, and its section sits behind a sensitive permission. "Reads like a
 * credential" is the kernel Redactor's key rule, the one source that also keeps such values out of logs, so a new
 * spelling (smtp_pass, api_key, signingKey) is caught here as soon as the Redactor knows it.
 */

/** Settings whose name matches the Redactor's rule but hold no credential, and why. */
const NOT_CREDENTIALS = [
    'billing.seller.pan' => 'The seller\'s Nepal Permanent Account Number, a tax ID printed on every invoice.',
    'billing.seller.vat_number' => 'The seller\'s VAT or PAN tax ID, printed on every invoice.',
    'notifications.signature' => 'The sign-off text appended to outgoing email.',
    'notifications.auth_link_host' => 'A host name allowed in account links; no secret.',
    'orders.transfer_requires_epp' => 'An on/off switch; no transfer code is stored.',
];

function looksLikeCredential(string $name): bool
{
    return app(Redactor::class)->isSensitiveKey($name);
}

/**
 * @return array<string, array{section: string, permission: ?string, secret: bool}>
 */
function credentialLikeSettings(): array
{
    $found = [];

    foreach (app(SettingsRegistry::class)->sections() as $sectionKey => $section) {
        foreach ($section['settings'] as $definition) {
            if (looksLikeCredential($definition->key) || looksLikeCredential($definition->label)) {
                $found[$definition->key] = ['section' => $sectionKey, 'permission' => $section['permission'], 'secret' => $definition->secret];
            }
        }
    }

    return $found;
}

test('every registered setting that looks like a credential is a secret setting', function () {
    $found = credentialLikeSettings();
    $plain = array_keys(array_filter($found, fn (array $setting): bool => ! $setting['secret']));

    expect($found)->toHaveKey('support.inbound_secret')
        ->and(array_values(array_diff($plain, array_keys(NOT_CREDENTIALS))))->toBe([])
        // Every exemption still matches and is still a plain setting, so the list cannot hide a stale entry.
        ->and(array_values(array_diff(array_keys(NOT_CREDENTIALS), $plain)))->toBe([]);
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

test('the credential rule catches the usual spellings and ignores ordinary names', function (string $name, bool $matches) {
    expect(looksLikeCredential($name))->toBe($matches);
})->with([
    ['acme.api_key', true],
    ['acme.apiKey', true],
    ['acme.webhook_secret', true],
    ['acme.access_token', true],
    ['acme.smtp_password', true],
    ['acme.smtp_pass', true],
    ['acme.passhash', true],
    ['acme.signing_key', true],
    ['License key', true],
    ['support.ticket_number_format', false],
    ['domains.keep_dns', false],
    ['Keyboard shortcuts', false],
]);
