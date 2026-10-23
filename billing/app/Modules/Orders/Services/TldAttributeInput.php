<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\TldAttributes;
use App\Data\Integrations\SettingField;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * The extension details a registrar asks for, as Orders uses them: the field lists the order forms show, and the
 * answers checked before they go into the cart. Orders works without Domains: then no extension has fields.
 */
class TldAttributeInput
{
    public function __construct(private Container $container, private DomainPricing $pricing) {}

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
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function clean(?string $domain, mixed $input): array
    {
        $tld = $domain === null ? null : ($this->pricing->split(strtolower(trim($domain)))[1] ?? null);

        return $tld === null ? [] : ($this->source()?->clean($tld, $input) ?? []);
    }

    private function source(): ?TldAttributes
    {
        return $this->container->bound(TldAttributes::class) ? $this->container->make(TldAttributes::class) : null;
    }
}
