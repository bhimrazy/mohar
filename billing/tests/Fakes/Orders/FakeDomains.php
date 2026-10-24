<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Data\Services\ProvisionResult;

class FakeDomains implements DomainPricing, DomainRegistry
{
    /** @var array<string, array{register: ?int, transfer: ?int, renew: ?int}> per year */
    public array $tlds = [
        '.com' => ['register' => 1200, 'transfer' => 1000, 'renew' => 1400],
        '.net' => ['register' => 1300, 'transfer' => 1100, 'renew' => 1500],
    ];

    /** @var array<int, DomainDraft> */
    public array $created = [];

    /** @var list<int> */
    public array $processed = [];

    /** @var array<int, string> */
    public array $cancelled = [];

    /** @var \Throwable|null thrown by createPending() when set */
    public ?\Throwable $createFails = null;

    private int $nextId = 500;

    /** @var list<?int> client ids passed to price() */
    public array $pricedFor = [];

    /** @var list<?int> client ids passed to terms() */
    public array $termsFor = [];

    public function price(string $tld, string $action, int $years, string $currency, ?int $clientId = null): ?int
    {
        $this->pricedFor[] = $clientId;
        $price = $this->tlds[$tld][$action] ?? null;

        return $price === null ? null : $price * $years;
    }

    public function terms(string $tld, string $action, string $currency, ?int $clientId = null): array
    {
        $this->termsFor[] = $clientId;

        return ($this->tlds[$tld][$action] ?? null) === null ? [] : [1, 2, 3, 5, 10];
    }

    public function catalog(string $currency, ?int $clientId = null): array
    {
        $rows = [];

        foreach ($this->tlds as $tld => $prices) {
            $rows[] = ['tld' => $tld, 'featured' => true, 'badge' => null, ...$prices];
        }

        return $rows;
    }

    public function split(string $domain): ?array
    {
        foreach (array_keys($this->tlds) as $tld) {
            if (str_ends_with($domain, $tld) && strlen($domain) > strlen($tld)) {
                return [substr($domain, 0, -strlen($tld)), $tld];
            }
        }

        return null;
    }

    public function createPending(DomainDraft $draft): int
    {
        if ($this->createFails !== null) {
            throw $this->createFails;
        }

        $id = $this->nextId++;
        $this->created[$id] = $draft;

        return $id;
    }

    public function process(int $domainId): ProvisionResult
    {
        $this->processed[] = $domainId;

        return new ProvisionResult(true);
    }

    public function cancelPending(int $domainId, string $status = 'cancelled'): void
    {
        $this->cancelled[$domainId] = $status;
    }
}
