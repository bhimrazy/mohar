<?php

namespace App\Modules\Platform\Services;

use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Failed sign-in lockout (spec §4): security.login_max_attempts failures for one email + IP lock that
 * pair for security.login_lockout_minutes, even with the right password. Allow-listed IPs are exempt.
 * Only failures count (Fortify's per-minute "login" limiter still throttles raw request volume).
 */
class LoginLockout
{
    public function __construct(private Settings $settings) {}

    public function key(string $email, string $ip): string
    {
        return 'login-lockout:'.Str::transliterate(Str::lower(trim($email))).'|'.$ip;
    }

    public function isLocked(string $email, string $ip): bool
    {
        return ! $this->isAllowlisted($ip) && RateLimiter::tooManyAttempts($this->key($email, $ip), $this->maxAttempts());
    }

    public function recordFailure(string $email, string $ip): void
    {
        if (! $this->isAllowlisted($ip)) {
            RateLimiter::hit($this->key($email, $ip), $this->lockoutMinutes() * 60);
        }
    }

    public function clear(string $email, string $ip): void
    {
        RateLimiter::clear($this->key($email, $ip));
    }

    public function secondsRemaining(string $email, string $ip): int
    {
        return RateLimiter::availableIn($this->key($email, $ip));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->settings->get('security.login_max_attempts', 5));
    }

    public function lockoutMinutes(): int
    {
        return max(1, (int) $this->settings->get('security.login_lockout_minutes', 15));
    }

    public function isAllowlisted(string $ip): bool
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->settings->get('security.login_ip_allowlist', '')) ?: []));

        return $list !== [] && IpUtils::checkIp($ip, $list);
    }
}
