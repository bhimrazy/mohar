<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Symfony keeps trusted host patterns in a static; reset it so earlier production-mode tests don't leak in.
    Request::setTrustedHosts([]);
    Notification::fake();
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

test('a forged Host header never changes the host of a password reset link', function () {
    $user = User::factory()->create();

    $this->withHeader('Host', 'evil.test')->post('http://evil.test/forgot-password', ['email' => $user->email]);

    $url = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    expect(parse_url((string) $url, PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and($url)->not->toContain('evil.test');
});

test('in production a request for a foreign host is rejected before any mail is sent', function () {
    $user = User::factory()->create();
    $this->app['env'] = 'production';

    $this->withHeader('Host', 'evil.test')->post('http://evil.test/forgot-password', ['email' => $user->email])
        ->assertBadRequest();

    Notification::assertNothingSent();
});
