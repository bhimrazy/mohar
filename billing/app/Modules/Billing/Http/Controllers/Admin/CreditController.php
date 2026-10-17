<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Http\Requests\Admin\CreditAdjustmentRequest;
use App\Modules\Billing\Http\Resources\CreditEntryResource;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit management per client: balance, ledger and manual adjustments.
 */
class CreditController extends Controller
{
    public function index(Request $request, CreditLedgerService $ledger): Response
    {
        $this->authorize('billing.credit.manage');

        $client = $request->filled('client') ? Client::query()->find($request->integer('client')) : null;

        $entries = CreditLedgerEntry::query()
            ->with(['client:id,first_name,last_name,company_name', 'staff:id,name'])
            ->when($client, fn ($query) => $query->where('client_id', $client?->id))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/billing/credit/index', [
            'client' => $client === null ? null : [
                'id' => $client->id,
                'display_name' => $client->display_name,
                'currency' => $client->currency,
                'balance' => $ledger->balance($client->id, $client->currency)->amount,
                'separate_invoices' => ClientFlag::query()->whereKey($client->id)->value('separate_invoices'),
            ],
            'entries' => CreditEntryResource::collection($entries),
            'clients' => Client::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(500)->get(['id', 'first_name', 'last_name', 'company_name'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'display_name' => $client->display_name]),
        ]);
    }

    /**
     * Per-client invoicing preference (separate invoice per renewal item, or the default).
     */
    public function preferences(Request $request, Client $client, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('billing.credit.manage');
        $data = $request->validate(['separate_invoices' => ['nullable', 'boolean']]);

        ClientFlag::query()->updateOrCreate(['client_id' => $client->id], ['separate_invoices' => $data['separate_invoices'] ?? null]);
        $activity->log('Invoicing preference changed', $client, $client->id, ['separate_invoices' => $data['separate_invoices'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoicing preference saved.')]);

        return back();
    }

    public function store(CreditAdjustmentRequest $request, CreditLedgerService $ledger, ActivityLogger $activity): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));
        $amount = Money::of($request->minor('amount', $client->currency), $client->currency);

        $entry = $ledger->post(
            $client->id,
            $request->string('direction')->toString() === 'remove' ? $amount->negate() : $amount,
            CreditReason::Manual,
            description: $request->string('description')->toString(),
            staffId: $request->user()?->id,
        );

        $activity->log('Credit balance adjusted', $entry, $client->id, ['amount' => $entry->amount, 'balance_after' => $entry->balance_after, 'reason' => $entry->description]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit balance updated.')]);

        return back();
    }
}
