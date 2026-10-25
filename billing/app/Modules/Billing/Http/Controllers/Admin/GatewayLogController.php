<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\GatewayLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GatewayLogController extends Controller
{
    public function index(Request $request, GatewayManager $gateways): Response
    {
        $this->authorize('billing.gateway_log.view');

        $filters = $request->validate([
            'gateway' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:callback,request,response'],
            'invoice' => ['nullable', 'integer'],
        ]);

        return Inertia::render('admin/billing/gateway-log/index', [
            'entries' => GatewayLog::query()
                ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
                ->when($filters['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
                ->when($filters['invoice'] ?? null, fn ($query, $invoice) => $query->where('invoice_id', $invoice))
                ->orderByDesc('id')
                // No count(*) over the whole log (#49).
                ->simplePaginate(50)
                ->withQueryString()
                ->through(fn (GatewayLog $entry): array => [
                    'id' => $entry->id,
                    'gateway' => $entry->gateway,
                    'direction' => $entry->direction,
                    'invoice_id' => $entry->invoice_id,
                    'result' => $entry->result,
                    'payload' => $entry->payload,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
            'filters' => (object) array_filter($filters),
            'gateways' => array_values(array_map(fn ($driver): array => ['value' => $driver->key(), 'label' => $driver->name()], $gateways->drivers())),
        ]);
    }
}
