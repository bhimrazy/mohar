<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Security review 2026-10-19, B1: the inbound email webhook secret is a write-only secret setting behind a
 * sensitive permission. Probes P1 and P1b of the review, inverted.
 */
const INBOUND_SECRET = 'inbound-secret-0123456789abcdef-XYZ';

beforeEach(function () {
    app(Settings::class)->set('support.inbound_secret', INBOUND_SECRET);
});

function signedInboundEmail(string $secret): TestResponse
{
    Department::query()->where('email', 'help@support.example.test')->exists() || Department::factory()->create(['email' => 'help@support.example.test']);
    $body = (string) json_encode(['message_id' => '<'.uniqid('m', true).'@mail.example>', 'from' => 'victim@example.com', 'to' => ['help@support.example.test'], 'subject' => 'Change my email', 'text' => 'Please', 'headers' => []]);

    return test()->call('POST', route('support.inbound.email', 'generic'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SUPPORT_SIGNATURE' => GenericDriver::sign($body, $secret),
    ], $body);
}

test('probe P1: neither a Support role nor view-only staff can read the inbound secret', function (array $permissions) {
    actingAsStaff($permissions);

    foreach (['support', 'support_inbound'] as $section) {
        $response = $this->get(route('admin.platform.settings.edit', ['section' => $section]));
        expect($response->getContent())->not->toContain(INBOUND_SECRET);
    }
})->with([
    'support.*' => [['support.*']],
    'core.settings.view' => [['core.settings.view']],
]);

test('probe P1: a Support role cannot change the inbound secret, and wildcards never grant it', function () {
    $user = actingAsStaff(['support.*']);
    confirmPassword();

    $this->put(route('admin.platform.settings.update', ['section' => 'support_inbound']), ['support' => ['inbound_secret' => str_repeat('a', 40)]])
        ->assertForbidden();

    expect($user->hasPermission('support.inbound_webhook.manage'))->toBeFalse()
        ->and(app(Settings::class)->get('support.inbound_secret'))->toBe(INBOUND_SECRET);
});

test('probe P1b: the secret is encrypted at rest and in the cache', function () {
    $stored = (string) json_decode((string) DB::table('settings')->where('key', 'support.inbound_secret')->value('value'));

    expect($stored)->toStartWith(Settings::ENCRYPTED_PREFIX)->not->toContain(INBOUND_SECRET)
        ->and(serialize(Cache::get('core.settings')))->not->toContain(INBOUND_SECRET);
});

test('probe P1b: changing the secret needs the exact permission and a recent password, and the audit diff is masked', function () {
    actingAsStaff(['support.inbound_webhook.manage']);
    $rotated = 'rotated-secret-0123456789abcdef-ABC';

    $this->put(route('admin.platform.settings.update', ['section' => 'support_inbound']), ['support' => ['inbound_secret' => $rotated]])
        ->assertSessionHasErrors('password_confirmation');
    expect(app(Settings::class)->get('support.inbound_secret'))->toBe(INBOUND_SECRET);

    confirmPassword();
    $this->put(route('admin.platform.settings.update', ['section' => 'support_inbound']), ['support' => ['inbound_secret' => 'too-short']])
        ->assertSessionHasErrors('support.inbound_secret');
    $this->put(route('admin.platform.settings.update', ['section' => 'support_inbound']), ['support' => ['inbound_secret' => $rotated]])
        ->assertSessionHasNoErrors();

    $log = json_encode(ActivityLog::query()->where('event', 'settings.updated')->latest('id')->firstOrFail()->properties);
    expect(app(Settings::class)->get('support.inbound_secret'))->toBe($rotated)
        ->and($log)->not->toContain($rotated)->not->toContain(INBOUND_SECRET);

    $this->get(route('admin.platform.settings.edit', ['section' => 'support_inbound']))->assertOk()->assertDontSee($rotated);
});

test('probe P1: after rotation the old key no longer signs mail as a client', function () {
    signedInboundEmail(INBOUND_SECRET)->assertCreated();

    app(Settings::class)->set('support.inbound_secret', 'rotated-secret-0123456789abcdef-ABC');
    signedInboundEmail(INBOUND_SECRET)->assertUnauthorized();

    expect(Ticket::query()->count())->toBe(1);
});

test('the migration encrypts an inbound secret stored in plaintext before the fix', function () {
    DB::table('settings')->where('key', 'support.inbound_secret')->update(['value' => json_encode('legacy-plaintext-secret')]);
    Cache::memo()->forget('core.settings');

    (require base_path('app/Modules/Support/Database/Migrations/2026_10_19_600600_encrypt_support_inbound_secret.php'))->up();

    expect((string) json_decode((string) DB::table('settings')->where('key', 'support.inbound_secret')->value('value')))->toStartWith(Settings::ENCRYPTED_PREFIX)->not->toContain('legacy-plaintext-secret')
        ->and(app(Settings::class)->get('support.inbound_secret'))->toBe('legacy-plaintext-secret');
});
