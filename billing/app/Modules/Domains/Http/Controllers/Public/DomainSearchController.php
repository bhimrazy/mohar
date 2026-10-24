<?php

namespace App\Modules\Domains\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Domains\Services\DomainLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public domain search page and JSON lookup (spec §6). Prices use the signed-in client's currency
 * and group; guests may pick a currency. With the store installed its domain search is the one search
 * page (issue #37): this page redirects there and the store reads availability from `lookup`.
 */
class DomainSearchController extends Controller
{
    public function page(Request $request, DomainLookup $lookup): Response|RedirectResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255'], 'currency' => ['nullable', 'string', 'size:3']]);

        if (Route::has('store.domains')) {
            $query = trim($request->string('q')->toString());

            return redirect()->route('store.domains', $query === '' ? [] : ['domain' => $query]);
        }

        [$currency, $group] = $this->pricingContext($request);
        $query = trim($request->string('q')->toString());

        return Inertia::render('public/domains/search', [
            'query' => $query,
            'currency' => $currency,
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'canChooseCurrency' => $this->client($request) === null,
            'search' => $query === '' ? null : $lookup->search($query, $currency, $group),
        ]);
    }

    public function lookup(Request $request, DomainLookup $lookup): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:255'], 'currency' => ['nullable', 'string', 'size:3']]);
        [$currency, $group] = $this->pricingContext($request);

        return response()->json($lookup->search($request->string('q')->toString(), $currency, $group));
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function pricingContext(Request $request): array
    {
        $client = $this->client($request);

        if ($client !== null) {
            return [$client->currency, $client->client_group_id];
        }

        $requested = strtoupper($request->string('currency')->toString());
        $currency = $requested !== '' && Currency::query()->where('code', $requested)->exists() ? $requested : Currency::default()->code;

        return [$currency, null];
    }

    private function client(Request $request): ?Client
    {
        $user = $request->user();

        if ($user === null || $user->is_staff) {
            return null;
        }

        $selected = $request->session()->get(ClientContext::SESSION_KEY);
        $clients = $user->clients()->where('status', '!=', 'closed');

        return ($selected ? (clone $clients)->whereKey($selected)->first() : null) ?? $clients->first();
    }
}
