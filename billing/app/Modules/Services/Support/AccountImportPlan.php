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

    /** @var array<string, int|null> */
    private array $prices = [];

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
        $clients = Client::query()->whereKey(array_values(array_unique(array_filter($clientIds))))->get(['id', 'first_name', 'last_name', 'company_name', 'currency', 'status', 'client_group_id'])->keyBy('id');
        $today = CarbonImmutable::today();

        return array_map(function (array $row) use ($selections, $products, $clients, $today): array {
            $choice = $selections[$row['username']] ?? [];
            $client = $clients->get($choice['client_id'] ?? $row['client_id'] ?? 0);
            $product = $products[$choice['product_id'] ?? $row['product_id'] ?? 0] ?? null;
            $cycle = BillingCycle::tryFrom((string) ($choice['cycle'] ?? '')) ?? $this->defaultCycle($product, $client);
            $due = isset($choice['next_due_date']) ? CarbonImmutable::parse($choice['next_due_date']) : $this->defaultDue($row['created_at'] ?? null, $cycle, $today);
            // The account's renewal day, kept for short months (a 31st renews on the 30th in November, then the 31st).
            $anchor = isset($choice['next_due_date']) || ($row['created_at'] ?? null) === null ? $due?->day : CarbonImmutable::parse($row['created_at'])->day;
            $amount = $client !== null && $product !== null && $cycle !== null ? $this->price($product, $cycle, $client) : null;
            // A client group's discount is why one package can show two prices; the list price says so on the review.
            $listAmount = $client?->client_group_id !== null && $product !== null && $cycle !== null ? $this->price($product, $cycle, $client, list: true) : null;

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
                'anchor_day' => $anchor,
                'amount' => $amount,
                'list_amount' => $listAmount !== $amount ? $listAmount : null,
                'problems' => $problems,
            ]];
        }, $fresh);
    }

    /**
     * The review page: every ticked account as rows() shows it, with the matched client pinned on the account (apply
     * never matches by email again), and the exact rows that can be imported recorded with their digest. Apply imports
     * only those rows, and only while they still hold (stillReviewed()).
     *
     * @return array{rows: list<array<string, mixed>>, digest: string}
     */
    public function review(Server $server, ServerAccountImport $import, ?int $staffId): array
    {
        $rows = $this->rows($server, $import);
        $selections = $import->selections ?? [];

        foreach ($rows as $row) {
            if ($row['decision']['problems'] === [] && ! isset($selections[$row['username']]['client_id'])) {
                $selections[$row['username']]['client_id'] = $row['decision']['client']['id'];
            }
        }

        $reviewed = self::snapshot($rows);
        $import->update(['selections' => $selections, 'reviewed' => ['digest' => self::digest($reviewed), 'rows' => $reviewed, 'staff_id' => $staffId]]);

        return ['rows' => $rows, 'digest' => self::digest($reviewed)];
    }

    /**
     * Why the reviewed rows no longer hold, or null when apply may import exactly them: the same accounts, clients,
     * products, cycles, dates and prices as the review page showed.
     */
    public function stillReviewed(Server $server, ServerAccountImport $import, string $digest): ?string
    {
        $reviewed = $import->reviewed;

        if ($reviewed === null || ! hash_equals($reviewed['digest'], $digest)) {
            return AccountImportMatcher::text('Review the import again: the accounts to import are not the ones you reviewed.');
        }

        $usernames = array_flip(array_column($reviewed['rows'], 'username'));
        $now = array_values(array_filter(self::snapshot($this->rows($server, $import)), fn (array $row): bool => isset($usernames[$row['username']])));

        return hash_equals($reviewed['digest'], self::digest($now)) ? null
            : AccountImportMatcher::text('Review the import again: an account, client, product or price changed since you reviewed it.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{username: string, client_id: int, product_id: int, cycle: string, next_due_date: string, anchor_day: int, amount: int}>
     */
    private static function snapshot(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => [
            'username' => (string) $row['username'],
            'client_id' => (int) $row['decision']['client']['id'],
            'product_id' => (int) $row['decision']['product']['id'],
            'cycle' => (string) $row['decision']['cycle'],
            'next_due_date' => (string) $row['decision']['next_due_date'],
            'anchor_day' => (int) $row['decision']['anchor_day'],
            'amount' => (int) $row['decision']['amount'],
        ], array_filter($rows, fn (array $row): bool => $row['decision']['problems'] === [])));
    }

    /**
     * @param  list<array<string, int|string>>  $rows
     */
    private static function digest(array $rows): string
    {
        return hash('sha256', (string) json_encode($rows));
    }

    /**
     * The recurring price, asked once per product, cycle, currency and client group (prices differ only by those); with
     * $list, the price before any group discount.
     */
    private function price(ProductSummary $product, BillingCycle $cycle, Client $client, bool $list = false): ?int
    {
        $key = "{$product->id}:{$cycle->value}:{$client->currency}:".($list ? 'list' : $client->client_group_id);

        if (! array_key_exists($key, $this->prices)) {
            $this->prices[$key] = $this->catalog->priceBook()?->quote(new ProductSelection($product->id, $cycle, $client->currency, clientId: $list ? null : $client->id))?->recurring;
        }

        return $this->prices[$key];
    }

    /**
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
