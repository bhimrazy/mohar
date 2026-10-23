<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\TldAttributes;
use App\Data\Integrations\SettingField;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * The extension details a registrar asks for, as Orders uses them: the field lists the order forms show, the answers
 * checked before they go into the cart, and how they read back to the customer. Orders works without Domains: this
 * class resolves nothing from it until a method needs it, and without a `TldAttributes` implementation no extension
 * has fields.
 */
class TldAttributeInput
{
    public function __construct(private Container $container) {}

    /**
     * Page props: extension => fields, only for extensions that have some.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function props(): array
    {
        $source = $this->source();

        return $source === null ? [] : array_map(
            fn (array $fields): array => array_map(fn (SettingField $field): array => $field->toArray(), $fields),
            $source->fieldsByTld(),
        );
    }

    /**
     * Checked answers for the extension of $domain; empty when it has no fields.
     *
     * @param  string  $domainKey  the form field a refusal that is about the domain itself is shown on
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function clean(?string $domain, mixed $input, string $domainKey = 'domain'): array
    {
        $source = $this->source();
        $tld = $source === null ? null : $this->tldOf($domain);

        if ($tld === null) {
            return [];
        }

        try {
            return $source->clean($tld, $input);
        } catch (ValidationException $exception) {
            if (! array_key_exists('domain', $exception->errors()) || $domainKey === 'domain') {
                throw $exception;
            }

            throw ValidationException::withMessages([$domainKey => $exception->errors()['domain'][0]]);
        }
    }

    /**
     * The stored answers as they read back to the customer (labels, ID numbers shortened to their last 3 characters).
     *
     * @param  array<string, string>  $answers
     * @return list<array{label: string, value: string}>
     */
    public function describe(?string $domain, array $answers): array
    {
        $tld = $answers === [] ? null : $this->tldOf($domain);

        return $tld === null ? [] : ($this->source()?->describe($tld, $answers) ?? []);
    }

    private function tldOf(?string $domain): ?string
    {
        return $domain === null || ! $this->container->bound(DomainPricing::class)
            ? null
            : ($this->container->make(DomainPricing::class)->split(strtolower(trim($domain)))[1] ?? null);
    }

    private function source(): ?TldAttributes
    {
        return $this->container->bound(TldAttributes::class) ? $this->container->make(TldAttributes::class) : null;
    }
}
