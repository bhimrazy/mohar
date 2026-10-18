<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client lookup for billing forms (invoices, quotes, transactions, credit): no cap on how many clients exist.
 */
class ClientSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(collect(['billing.invoices.manage', 'billing.quotes.manage', 'billing.transactions.manage', 'billing.credit.manage'])
            ->contains(fn (string $permission): bool => $request->user()?->hasPermission($permission) ?? false), 403);

        $term = trim($request->string('q')->toString());

        $clients = Client::query()
            ->where('status', '!=', 'closed')
            ->when($term !== '', fn ($query) => $query->search(mb_substr($term, 0, 100)))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'currency']);

        return response()->json($clients->map(fn (Client $client): array => [
            'id' => $client->id,
            'display_name' => $client->display_name,
            'email' => $client->email,
            'currency' => $client->currency,
        ])->values());
    }
}
