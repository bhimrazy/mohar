<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;

/**
 * Password-reset and email-verification links, always built on APP_URL — never on the request's Host header, which
 * an attacker controls on forgot-password (host-header poisoning, security review PR #28). Used by Laravel's
 * notifications (AppServiceProvider) and by every AuthMailer.
 */
final class AccountLinks
{
    public static function passwordReset(User $user, #[\SensitiveParameter] string $token): string
    {
        return self::generator()->route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
    }

    public static function passwordSet(#[\SensitiveParameter] string $token): string
    {
        return self::generator()->route('password.set', ['token' => $token]);
    }

    public static function emailVerification(User $user): string
    {
        return self::generator()->temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    /**
     * Why no account link (reset, verification, invitation) may be mailed with this configuration, or null when they
     * can be: APP_URL needs a host, and https in production. Every sender checks this before mailing a one-time link,
     * with or without the Notifications module.
     */
    public static function problem(): ?string
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return __('APP_URL ":url" has no host, so password-reset, verification and invitation emails are held. Set APP_URL to the public https:// address.', ['url' => $url]);
        }

        if (app()->isProduction() && strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return __('APP_URL is ":url": in production account links must use https://, so password-reset, verification and invitation emails are held. Set APP_URL to the https:// address.', ['url' => $url]);
        }

        return null;
    }

    /**
     * Runs $callback (rendering or sending mail) with every url(), route() and asset() rooted at APP_URL, then
     * restores the request's generator. Mail never carries links built from a forged Host header.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withAppOrigin(Closure $callback): mixed
    {
        $original = app('url');
        app()->instance('url', self::generator());
        URL::clearResolvedInstance('url');

        try {
            return $callback();
        } finally {
            app()->instance('url', $original);
            URL::clearResolvedInstance('url');
        }
    }

    /**
     * A fresh URL generator rooted at APP_URL (https in production). Not a clone of the shared one: a clone keeps
     * its cached route generator, which still points at the request-rooted original.
     */
    public static function generator(): UrlGenerator
    {
        $root = rtrim((string) config('app.url'), '/');
        $generator = new UrlGenerator(app('router')->getRoutes(), Request::create($root), config('app.asset_url'));
        $generator->forceRootUrl($root);
        $generator->forceScheme(app()->isProduction() ? 'https' : ((string) parse_url($root, PHP_URL_SCHEME) ?: 'https'));
        $generator->setKeyResolver(fn (): array => [config('app.key'), ...(array) config('app.previous_keys', [])]);

        return $generator;
    }
}
