<?php

namespace App\Support\Auth;

use App\Data\Auth\SignInMethod;
use Closure;

/**
 * The sign-in methods modules offer on the login and sign-up pages (ADR 0018 §1). A module registers a closure in
 * bootModule(); it runs per request and returns null while the method is off, so an administrator's change applies at
 * once. The login view never names a method itself.
 *
 *   app(SignInMethods::class)->register('google', fn (): ?SignInMethod => $enabled ? new SignInMethod(...) : null);
 *
 * @api
 */
final class SignInMethods
{
    /** @var array<string, Closure(): ?SignInMethod> */
    private array $methods = [];

    /**
     * @param  Closure(): ?SignInMethod  $resolver
     */
    public function register(string $key, Closure $resolver): void
    {
        $this->methods[$key] = $resolver;
    }

    /**
     * The methods available now, in registration order.
     *
     * @return list<SignInMethod>
     */
    public function available(): array
    {
        return array_values(array_filter(array_map(fn (Closure $resolver): ?SignInMethod => $resolver(), $this->methods)));
    }

    /**
     * For the login and register views.
     *
     * @return list<array<string, mixed>>
     */
    public function forView(): array
    {
        return array_map(fn (SignInMethod $method): array => $method->toArray(), $this->available());
    }
}
