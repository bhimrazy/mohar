<?php

namespace App\Modules\Notifications\Support;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\TemplateType;

/**
 * Who our email comes from and where replies go (owner decisions 2026-10-18). Replies go: always a real, monitored address, never a
 * no-reply one, and nothing at all when none is configured.
 *
 *  - billing mail (invoice templates)  → the billing address (core.company_email), else the support address
 *  - support tickets                   → the ticket department's inbound address (keeps threading), else support
 *  - everything else                   → notifications.support_email, else core.company_email
 */
final class SupportAddresses
{
    public function __construct(private Settings $settings) {}

    /**
     * The From address: notifications.from.billing for billing mail, else notifications.from.default,
     * else MAIL_FROM_ADDRESS (config mail.from.address).
     */
    public function from(TemplateType $type): string
    {
        $configured = $type === TemplateType::Invoice ? self::usableSender($this->settings->get('notifications.from.billing')) : null;

        return $configured
            ?? self::usableSender($this->settings->get('notifications.from.default'))
            ?? (string) config('mail.from.address');
    }

    /**
     * The From display name: the company name from settings (never the app name).
     */
    public function fromName(): string
    {
        $name = $this->settings->get('core.company_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : app(BrandProvider::class)->brand()->name;
    }

    public function general(): ?string
    {
        return self::usable($this->settings->get('notifications.support_email'))
            ?? self::usable($this->settings->get('core.company_email'));
    }

    public function billing(): ?string
    {
        return self::usable($this->settings->get('core.company_email')) ?? $this->general();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function replyTo(TemplateType $type, array $context): ?string
    {
        return match ($type) {
            TemplateType::Invoice => $this->billing(),
            TemplateType::Support => self::usable($context['ticket']['department_email'] ?? null) ?? $this->general(),
            default => $this->general(),
        };
    }

    private static function usableSender(mixed $address): ?string
    {
        return is_string($address) && filter_var($address = mb_strtolower(trim($address)), FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /**
     * A syntactically valid address that a person can answer from; no-reply style mailboxes don't count.
     */
    public static function usable(mixed $address): ?string
    {
        if (! is_string($address) || filter_var($address = mb_strtolower(trim($address)), FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return preg_match('/^(no-?reply|do-?not-?reply|noreply)[^@]*@/', $address) ? null : $address;
    }
}
