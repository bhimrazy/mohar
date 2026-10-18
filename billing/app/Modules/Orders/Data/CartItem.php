<?php

namespace App\Modules\Orders\Data;

use App\Enums\BillingCycle;
use App\Modules\Orders\Enums\CartItemKind;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * One cart entry as stored in carts.items (json, schema version 1). Prices are never stored:
 * the cart is re-quoted on every read.
 *
 * Product items optionally carry a domain choice (register|transfer|existing) and addon ids that
 * follow the product's cycle. Standalone domain items register or transfer a domain for N years.
 */
final readonly class CartItem
{
    /**
     * @param  array<int, int>  $configOptions  option id => value id (or quantity)
     * @param  array<int, string>  $customFields  field id => value
     * @param  list<int>  $addonIds
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $key,
        public CartItemKind $kind,
        public ?int $productId = null,
        public ?BillingCycle $cycle = null,
        public array $configOptions = [],
        public array $customFields = [],
        public ?string $domainMode = null,
        public ?string $domainName = null,
        public ?string $eppCode = null,
        public array $addonIds = [],
        public int $years = 1,
        public bool $idProtection = false,
        public array $nameservers = [],
    ) {}

    public static function newKey(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Domain name this item registers, transfers or attaches (lowercase), if any.
     */
    public function domain(): ?string
    {
        return $this->domainName === null || $this->domainName === '' ? null : strtolower($this->domainName);
    }

    /**
     * Whether this item creates a domain registration/transfer.
     */
    public function domainAction(): ?string
    {
        return match ($this->kind) {
            CartItemKind::DomainRegister => 'register',
            CartItemKind::DomainTransfer => 'transfer',
            CartItemKind::Product => in_array($this->domainMode, ['register', 'transfer'], true) ? $this->domainMode : null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $epp = $data['epp_code'] ?? null;

        if (is_string($epp) && $epp !== '') {
            try {
                $epp = Crypt::decryptString($epp);
            } catch (DecryptException) {
                $epp = null;
            }
        }

        return new self(
            key: (string) ($data['key'] ?? self::newKey()),
            kind: CartItemKind::from((string) $data['kind']),
            productId: isset($data['product_id']) ? (int) $data['product_id'] : null,
            cycle: isset($data['cycle']) ? BillingCycle::tryFrom((string) $data['cycle']) : null,
            configOptions: self::intMap($data['config_options'] ?? []),
            customFields: array_map(strval(...), (array) ($data['custom_fields'] ?? [])),
            domainMode: isset($data['domain_mode']) ? (string) $data['domain_mode'] : null,
            domainName: isset($data['domain_name']) ? (string) $data['domain_name'] : null,
            eppCode: is_string($epp) && $epp !== '' ? $epp : null,
            addonIds: array_values(array_map(intval(...), (array) ($data['addon_ids'] ?? []))),
            years: max(1, (int) ($data['years'] ?? 1)),
            idProtection: (bool) ($data['id_protection'] ?? false),
            nameservers: array_values(array_map(strval(...), (array) ($data['nameservers'] ?? []))),
        );
    }

    /**
     * Storage form; the EPP code is encrypted at rest.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'product_id' => $this->productId,
            'cycle' => $this->cycle?->value,
            'config_options' => $this->configOptions,
            'custom_fields' => $this->customFields,
            'domain_mode' => $this->domainMode,
            'domain_name' => $this->domain(),
            'epp_code' => $this->eppCode === null ? null : Crypt::encryptString($this->eppCode),
            'addon_ids' => $this->addonIds,
            'years' => $this->years,
            'id_protection' => $this->idProtection,
            'nameservers' => $this->nameservers,
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function intMap(mixed $values): array
    {
        $map = [];

        foreach ((array) $values as $key => $value) {
            if (is_numeric($key) && is_numeric($value)) {
                $map[(int) $key] = (int) $value;
            }
        }

        return $map;
    }
}
