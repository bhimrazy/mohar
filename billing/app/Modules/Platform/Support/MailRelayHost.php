<?php

namespace App\Modules\Platform\Support;

use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An SMTP host staff type in, judged by the kernel guard with the relay profile (ADR 0015 amendment #532). Checked
 * only while SMTP is the chosen mailer, so a host left over from an earlier choice cannot block saving.
 */
final class MailRelayHost implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (data_get($this->data, 'mail.default') !== 'smtp' || ! is_string($value) || $value === '') {
            return;
        }

        if (($refusal = self::refusal(app(OutboundAddressGuard::class), $value)) !== null) {
            $fail($refusal);
        }
    }

    /** Why the guard refuses this host as a mail relay, or null. */
    public static function refusal(OutboundAddressGuard $guard, string $host): ?string
    {
        try {
            $guard->resolve($host, 587, relay: true);
        } catch (OutboundAddressRefused $refused) {
            return $refused->getMessage();
        }

        return null;
    }
}
