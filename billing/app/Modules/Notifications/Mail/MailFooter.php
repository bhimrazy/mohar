<?php

namespace App\Modules\Notifications\Mail;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Support\SupportAddresses;
use Illuminate\Support\Facades\Route;

/**
 * The footer every email ends with (design §3 block 12), for the HTML and the text part: brand and tagline, the legal
 * name and address, the address replies reach, help and preference links, and why the recipient gets this email.
 */
final readonly class MailFooter
{
    public function __construct(
        public string $brand,
        public string $tagline,
        public string $address,
        public ?string $help,
        public string $helpUrl,
        public ?string $preferencesUrl,
        public ?string $reason,
        public bool $security,
    ) {}

    /**
     * @param  string|null  $supportEmail  the message's Reply-To, so "write to …" names the address replies reach
     */
    public static function make(?string $preferencesUrl = null, ?string $supportEmail = null, ?MailFrame $frame = null): self
    {
        $settings = app(Settings::class);
        $brand = app(BrandProvider::class)->brand();
        $legal = trim((string) ($settings->get('billing.seller.legal_name') ?: ''));
        $address = trim((string) ($settings->get('notifications.footer_address') ?: $settings->get('billing.seller.address', '')));
        $address = trim(implode(', ', array_filter(array_map('trim', preg_split('/\R/', $address) ?: []))));
        $supportEmail ??= app(SupportAddresses::class)->general();
        $template = (string) ($settings->get('notifications.footer_help_text') ?: 'Questions? Reply to this email or write to {{ support.email }}.');

        return new self(
            brand: $brand->name,
            tagline: $brand->tagline,
            address: implode(', ', array_filter([$legal !== '' && $legal !== $brand->name ? $legal : null, $address !== '' ? $address : null])),
            help: $supportEmail !== null ? str_replace('{{ support.email }}', $supportEmail, $template) : null,
            // The help centre is the clients' one; staff mail carries no link to it.
            helpUrl: $frame?->staff ? '' : (string) ($settings->get('notifications.support_url') ?: (Route::has('client.support.tickets.index') ? route('client.support.tickets.index') : '')),
            preferencesUrl: $frame?->security ? null : $preferencesUrl,
            reason: $frame?->reason,
            security: $frame !== null && $frame->security,
        );
    }

    public function text(): string
    {
        return implode("\n", array_filter([
            $this->brand.($this->tagline !== '' ? ' · '.$this->tagline : ''),
            $this->address,
            $this->help,
            $this->helpUrl !== '' ? self::label('Help centre').': '.$this->helpUrl : null,
            $this->preferencesUrl !== null ? self::label('Email preferences').': '.$this->preferencesUrl : null,
            $this->security ? self::label('Security emails are always sent and can\'t be turned off.') : $this->reason,
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }

    private static function label(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
