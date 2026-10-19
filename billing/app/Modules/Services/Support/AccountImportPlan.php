<?php

namespace App\Modules\Services\Support;

use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use Carbon\CarbonImmutable;

/**
 * What Import accounts would do with the accounts staff ticked (cPanel spec §10 steps 3–4), worked out again from
 * today's data each time it is shown or applied, never from what was stored at fetch time:
 *
 * - only accounts staff ticked are included; nothing is ticked by default;
 * - each ticked account is matched again (AccountImportMatcher::classify()) against today's services, clients and
 *   products; staff choices win over the match, and an account owned by another WHM user needs a chosen client;
 * - a problem keeps the account out: held by a live service, no active client, a product that does not provision on
 *   this server, no price in the client's currency.
 *
 * Cycle defaults to monthly (else the product's first recurring cycle in the client's currency); the next due date to
 * the next anniversary of the account's creation on the server.
 */
class AccountImportPlan
{
    /** @var array<string, list<BillingCycle>> */
    private array $cycles = [];

    public function __construct(private AccountImportMatcher $matcher, private Catalog $catalog) {}

    /**
     * The ticked accounts, each with a `decision`: client, product, cycle, next_due_date, amount (minor units, in the
     * client's currency) and problems.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Server $server, ServerAccountImport $import): array
    {
        $selections = $import->selections ?? [];
        $accounts = array_values(array_filter($import->accounts ?? [], fn (array $row): bool => (bool) ($selections[$row['username']]['include'] ?? false)));

        if ($accounts === []) {
            return [];
        }

        $keys = array_flip(['category', 'client_id', 'product_id', 'service_id', 'note', 'differences']);
        // Owners are compared with the user the fetch signed in as, not the server's current credentials.
        $fresh = $this->matcher->classify($server, array_map(fn (array $row): array => array_diff_key($row, $keys), $accounts), $import->connection_user)['rows'];
        $products = $this->matcher->serverProducts($server);
        $clientIds = array_map(fn (array $row): int => (int) ($selections[$row['username']]['client_id'] ?? $row['client_id'] ?? 0), $fresh);
        $clients = Client::query()->whereKey(array_values(array_unique(array_filter($clientIds))))->get(['id', 'first_name', 'last_name', 'company_name', 'currency', 'status'])->keyBy('id');
        $today = CarbonImmutable::today();

        return array_map(function (array $row) use ($selections, $products, $clients, $today): array {
            $choice = $selections[$row['username']] ?? [];
            $client = $clients->get($choice['client_id'] ?? $row['client_id'] ?? 0);
            $product = $products[$choice['product_id'] ?? $row['product_id'] ?? 0] ?? null;
            $cycle = BillingCycle::tryFrom((string) ($choice['cycle'] ?? '')) ?? $this->defaultCycle($product, $client);
            $due = isset($choice['next_due_date']) ? CarbonImmutable::parse($choice['next_due_date']) : $this->defaultDue($row['created_at'] ?? null, $cycle, $today);
            $amount = $client !== null && $product !== null && $cycle !== null
                ? $this->catalog->priceBook()?->quote(new ProductSelection($product->id, $cycle, $client->currency, clientId: $client->id))?->recurring
                : null;

            $problems = array_values(array_filter([
                $row['category'] === AccountImportMatcher::LINKED ? AccountImportMatcher::text('A live service on this server already holds this account.') : null,
                $row['category'] === AccountImportMatcher::RESELLER && ! isset($choice['client_id']) ? AccountImportMatcher::text('Owned by another WHM user: choose its client yourself.') : null,
                $client === null ? AccountImportMatcher::text('Choose a client.') : null,
                $client !== null && $client->status !== 'active' ? AccountImportMatcher::text('The client :name is not active.', ['name' => $client->display_name]) : null,
                $product === null ? AccountImportMatcher::text('Choose a product that provisions on this server.') : null,
                $product !== null && $client !== null && $amount === null ? AccountImportMatcher::text(':product has no :cycle price in :currency.', ['product' => $product->name, 'cycle' => $cycle->value ?? 'recurring', 'currency' => $client->currency]) : null,
            ]));

            return [...$row, 'decision' => [
                'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->display_name, 'currency' => $client->currency],
                'product' => $product === null ? null : ['id' => $product->id, 'name' => $product->name],
                'cycle' => $cycle?->value,
                'next_due_date' => $due?->toDateString(),
                'amount' => $amount,
                'problems' => $problems,
            ]];
        }, $fresh);
    }

    /**
     * The products staff may pick for an account on this server.
     *
     * @return list<array{id: int, name: string}>
     */
    public function productOptions(Server $server): array
    {
        return array_values(array_map(fn (ProductSummary $product): array => ['id' => $product->id, 'name' => $product->name], $this->matcher->serverProducts($server)));
    }

    private function defaultCycle(?ProductSummary $product, ?Client $client): ?BillingCycle
    {
        if ($product === null || $client === null) {
            return null;
        }

        $cycles = $this->cycles["{$product->id}:{$client->currency}"] ??= array_values(array_filter(
            $this->catalog->priceBook()?->availableCycles($product->id, $client->currency) ?? [],
            fn (BillingCycle $cycle): bool => $cycle->isRecurring(),
        ));

        return in_array(BillingCycle::Monthly, $cycles, true) ? BillingCycle::Monthly : ($cycles[0] ?? null);
    }

    /**
     * The first date after today that is a whole number of cycles from the account's creation (its renewal date).
     */
    private function defaultDue(?string $createdAt, ?BillingCycle $cycle, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($cycle === null) {
            return null;
        }

        $start = $createdAt === null ? $today : CarbonImmutable::parse($createdAt)->startOfDay();
        $periods = intdiv(max(0, (int) floor($start->diffInMonths($today))), (int) $cycle->months());
        $due = $cycle->advance($start, $periods, $start->day);

        while ($due->lessThanOrEqualTo($today)) {
            $due = $cycle->advance($start, ++$periods, $start->day);
        }

        return $due;
    }
}
