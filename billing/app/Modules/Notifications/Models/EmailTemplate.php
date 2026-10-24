<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Database\Factories\EmailTemplateFactory;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property TemplateType $type
 * @property TemplateAudience $audience
 * @property string $name
 * @property bool $is_system
 * @property bool $is_enabled
 * @property string|null $from_name
 * @property string|null $from_email
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property bool $attach_invoice_pdf
 * @property bool $attach_quote_pdf
 * @property bool $plain_text_only
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, EmailTemplateTranslation> $translations
 */
#[UseFactory(EmailTemplateFactory::class)]
#[Fillable([
    'key', 'type', 'audience', 'name', 'is_system', 'is_enabled', 'from_name', 'from_email',
    'cc', 'bcc', 'attach_invoice_pdf', 'attach_quote_pdf', 'plain_text_only',
])]
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => TemplateType::class,
            'audience' => TemplateAudience::class,
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
            'cc' => 'array',
            'bcc' => 'array',
            'attach_invoice_pdf' => 'boolean',
            'attach_quote_pdf' => 'boolean',
            'plain_text_only' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmailTemplateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(EmailTemplateTranslation::class);
    }

    /** The language every seeded template is written in. */
    public const BASE_LOCALE = 'en';

    /**
     * The translation for the locale, else the default locale, else the base locale, else any row —
     * a missing translation never stops an email (spec §3 locale fallback).
     */
    public function translationFor(string $locale, string $defaultLocale): ?EmailTemplateTranslation
    {
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', $defaultLocale)
            ?? $translations->firstWhere('locale', self::BASE_LOCALE)
            ?? $translations->sortBy('id')->first();
    }

    /**
     * Mail carrying a one-time link (reset, verification, invitation): structure-only, never copied to anyone
     * (security review B1). The list lives in SecretTemplatePolicy::ACTION_LINKS.
     */
    public function carriesSecret(): bool
    {
        return SecretTemplatePolicy::isSecretTemplate($this->key);
    }

    /** Account-access mail that must always go out: disabling the template has no effect. */
    /** Account access and security mail: always sent, so it can't be switched off (only its wording edited). */
    public const ALWAYS_SENT = [
        'auth.password_reset', 'auth.password_set', 'auth.email_verification', 'staff.password_reset', 'auth.user_invitation', 'staff.invitation',
        'client.migration_invitation', 'auth.login_added', 'auth.access_granted', 'auth.owner_changed', 'auth.two_factor_reset', 'staff.two_factor_reset',
        'staff.email_changed', 'staff.settings_changed', 'auth.email_changed', 'ticket.link_replaced',
    ];

    public function isAlwaysSent(): bool
    {
        return in_array($this->key, self::ALWAYS_SENT, true);
    }

    /**
     * Support subjects keep a fixed format so mail clients thread replies (spec §3).
     */
    public function hasFixedSubject(): bool
    {
        return $this->type === TemplateType::Support && $this->audience === TemplateAudience::Client && $this->key !== 'ticket.link_replaced';
    }
}
