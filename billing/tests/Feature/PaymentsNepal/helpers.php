<?php

/*
| Shared helpers for the Nepal gateway driver tests (require_once from each test file).
*/

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * A signed-in NPR client with one unpaid invoice of the given lines (paisa).
 *
 * @param  list<int>  $lines
 * @return array{0: Client, 1: Invoice}
 */
function nprInvoice(array $lines = [150_000]): array
{
    Currency::query()->where('code', 'NPR')->exists() || Currency::factory()->npr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));

    return [$client, issueInvoice($client, $lines)];
}

/**
 * An active, client-facing Nepal gateway row with the given driver settings.
 *
 * @param  array<string, string>  $config
 */
function nepalGateway(string $key, array $config): Gateway
{
    return Gateway::query()->create([
        'key' => $key,
        'display_name' => ucfirst($key),
        'is_active' => true,
        'show_on_order_form' => true,
        'sort_order' => 5,
        'config' => $config,
    ]);
}
