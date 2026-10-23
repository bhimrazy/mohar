<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Contracts\Billing\Invoicer;
use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\DeleteOrder;
use App\Modules\Orders\Actions\MarkOrderFraud;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\RunFraudCheck;
use App\Modules\Orders\Actions\SetOrderPending;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\AcceptOrderRequest;
use App\Modules\Orders\Http\Requests\BulkOrderRequest;
use App\Modules\Orders\Http\Requests\StaffOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\TldAttributeInput;
use App\Modules\Orders\Services\CheckoutGateways;
use App\Modules\Orders\Services\InvoiceLinks;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private Invoicer $invoicer,
        private InvoiceLinks $links,
    ) {}

    public function index(Request $request, CheckoutGateways $gateways): Response
    {
        $this->authorize('orders.orders.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $orders = Order::query()
            ->with('client')
            ->withCount('items')
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('client'), fn ($query) => $query->where('client_id', $request->integer('client')))
            ->when($request->filled('gateway'), fn ($query) => $query->where('payment_gateway', $request->string('gateway')->toString()))
            ->when($request->filled('fraud'), fn ($query) => $query->where('fraud_decision', $request->string('fraud')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')?->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<', $request->date('to')?->addDay()->startOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/orders/index', [
            'orders' => OrderResource::collection($orders),
            'filters' => $request->only(['search', 'status', 'client', 'gateway', 'fraud', 'from', 'to']),
            'statuses' => OrderStatus::values(),
            'fraud_decisions' => array_column(FraudDecision::cases(), 'value'),
            'tld_attribute_fields' => $tldAttributes->props(),
            'gateways' => collect($gateways->all(Currency::default()->code))->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
            'client' => $request->filled('client') ? Client::query()->find($request->integer('client'))?->only(['id', 'display_name']) : null,
            'can' => [
                'create' => $request->user()?->can('orders.orders.create') ?? false,
                'accept' => $request->user()?->can('orders.orders.accept') ?? false,
                'cancel' => $request->user()?->can('orders.orders.cancel') ?? false,
                'delete' => $request->user()?->can('orders.orders.delete') ?? false,
            ],
        ]);
    }

    public function show(Request $request, Order $order, AcceptOrder $accept): Response
    {
        $this->authorize('orders.orders.view');

        $order->load(['client', 'items', 'fraudChecks' => fn ($query) => $query->orderByDesc('id'), 'placedByStaff:id,name']);
        $invoiceStatus = $order->invoice_id === null ? null : $this->invoicer->status($order->invoice_id);
        $user = $request->user();

        return Inertia::render('admin/orders/show', [
            'order' => new OrderResource($order),
            'invoice' => $order->invoice_id === null ? null : [
                'id' => $order->invoice_id,
                'status' => $invoiceStatus,
                'url' => $this->links->admin($order->invoice_id),
            ],
            'fraud_checks' => $order->fraudChecks->map(fn ($check): array => [
                'id' => $check->id,
                'provider' => $check->provider,
                'score' => $check->score,
                'decision' => $check->decision->value,
                'reasons' => $check->reasons,
                'duration_ms' => $check->duration_ms,
                'created_at' => $check->created_at?->toIso8601String(),
            ]),
            'default_run_create' => $accept->defaultSelection($order, $invoiceStatus),
            'client_url' => Route::has('admin.clients.show') ? route('admin.clients.show', $order->client_id) : null,
            'placed_by_staff' => $order->placedByStaff?->name,
            'can' => [
                'accept' => ($user?->can('orders.orders.accept') ?? false) && $order->status === OrderStatus::Pending,
                'cancel' => ($user?->can('orders.orders.cancel') ?? false) && $order->status !== OrderStatus::Cancelled,
                'mark_fraud' => ($user?->can('orders.orders.mark_fraud') ?? false) && in_array($order->status, [OrderStatus::Pending, OrderStatus::Active], true),
                'set_pending' => ($user?->can('orders.orders.mark_fraud') ?? false) && $order->status === OrderStatus::Fraud,
                'delete' => $user?->can('orders.orders.delete') ?? false,
                'run_fraud_check' => $user?->can('orders.orders.mark_fraud') ?? false,
            ],
        ]);
    }

    public function create(Request $request, PriceBook $priceBook, CheckoutGateways $gateways, TldAttributeInput $tldAttributes): Response
    {
        $this->authorize('orders.orders.create');

        $client = $request->filled('client') ? Client::query()->find($request->integer('client')) : null;

        return Inertia::render('admin/orders/create', [
            'client' => $client?->only(['id', 'display_name', 'email', 'currency']),
            'clients' => Client::query()->where('status', 'active')->orderBy('first_name')->orderBy('id')->limit(200)->get()
                ->map(fn (Client $client): array => ['value' => (string) $client->id, 'label' => "{$client->display_name} ({$client->email})"]),
            'groups' => $client === null ? [] : $priceBook->storefront($client->currency),
            'gateways' => collect($gateways->all($client->currency ?? Currency::default()->code))->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
        ]);
    }

    public function store(StaffOrderRequest $request, PlaceOrder $placeOrder, TldAttributeInput $tldAttributes): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));
        $item = new CartItem(
            key: CartItem::newKey(),
            kind: CartItemKind::Product,
            productId: $request->integer('product_id'),
            cycle: BillingCycle::from($request->string('cycle')->toString()),
            domainMode: $request->input('domain_mode') ?: null,
            domainName: $request->filled('domain_name') ? $request->string('domain_name')->lower()->toString() : null,
            eppCode: $request->input('epp_code') ?: null,
            tldAttributes: $request->input('domain_mode') === 'register' ? $tldAttributes->clean($request->input('domain_name'), $request->input('tld_attributes')) : [],
        );

        $placed = $placeOrder->handle(new CheckoutData(
            items: [$item],
            currency: $client->currency,
            paymentGateway: $request->string('payment_gateway')->toString(),
            promotionCode: $request->input('promotion_code') ?: null,
            client: $client,
            userId: null,
            staffId: $request->user()?->id,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            idempotencyKey: (string) Str::uuid(),
            skipFraud: $request->boolean('skip_fraud'),
            notifyInvoice: $request->boolean('send_invoice', true),
            priceOverrides: $request->filled('price_override') ? [$item->key => Money::fromDecimal((string) $request->input('price_override'), $client->currency)->amount] : [],
        ));

        if ($request->filled('admin_notes')) {
            $placed->order->update(['admin_notes' => $request->string('admin_notes')->toString()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number placed.', ['number' => $placed->order->number])]);

        return to_route('admin.orders.show', $placed->order);
    }

    public function accept(AcceptOrderRequest $request, Order $order, AcceptOrder $accept): RedirectResponse
    {
        $invoiceStatus = $order->invoice_id === null ? null : $this->invoicer->status($order->invoice_id);

        return $this->attempt(function () use ($accept, $order, $request, $invoiceStatus): void {
            ['failures' => $failures, 'queued' => $queued] = $accept->handle($order, $request->runCreate(), $invoiceStatus);

            Inertia::flash('toast', match (true) {
                $failures !== [] => ['type' => 'warning', 'message' => __('Order :number accepted. Provisioning failed for: :items', ['number' => $order->number, 'items' => implode('; ', $failures)])],
                $queued !== [] => ['type' => 'success', 'message' => __('Order :number accepted. Provisioning queued for: :items', ['number' => $order->number, 'items' => implode('; ', $queued)])],
                default => ['type' => 'success', 'message' => __('Order :number accepted.', ['number' => $order->number])],
            });
        });
    }

    public function cancel(Order $order, CancelOrder $cancel): RedirectResponse
    {
        $this->authorize('orders.orders.cancel');

        return $this->attempt(function () use ($cancel, $order): void {
            $cancel->handle($order);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number cancelled.', ['number' => $order->number])]);
        });
    }

    public function markFraud(Order $order, MarkOrderFraud $markFraud): RedirectResponse
    {
        $this->authorize('orders.orders.mark_fraud');

        return $this->attempt(function () use ($markFraud, $order): void {
            $markFraud->handle($order);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number marked as fraud.', ['number' => $order->number])]);
        });
    }

    public function setPending(Order $order, SetOrderPending $setPending): RedirectResponse
    {
        $this->authorize('orders.orders.mark_fraud');

        return $this->attempt(function () use ($setPending, $order): void {
            $setPending->handle($order);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number set back to pending.', ['number' => $order->number])]);
        });
    }

    public function runFraudCheck(Order $order, RunFraudCheck $run): RedirectResponse
    {
        $this->authorize('orders.orders.mark_fraud');

        $decision = $run->handle($order);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fraud check finished: :decision.', ['decision' => $decision->value])]);

        return back();
    }

    public function destroy(Order $order, DeleteOrder $delete): RedirectResponse
    {
        $this->authorize('orders.orders.delete');

        try {
            $delete->handle($order);
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number deleted.', ['number' => $order->number])]);

        return to_route('admin.orders.index');
    }

    public function bulk(BulkOrderRequest $request, AcceptOrder $accept, CancelOrder $cancel, DeleteOrder $delete): RedirectResponse
    {
        $this->authorize($request->permission());

        $done = 0;
        $skipped = 0;

        Order::query()->whereIn('id', $request->input('ids'))->orderBy('id')->each(function (Order $order) use ($request, $accept, $cancel, $delete, &$done, &$skipped): void {
            try {
                match ($request->string('action')->toString()) {
                    'accept' => $accept->handle($order, invoiceStatus: $order->invoice_id === null ? null : $this->invoicer->status($order->invoice_id)),
                    'cancel' => $cancel->handle($order),
                    default => $delete->handle($order),
                };
                $done++;
            } catch (DomainException) {
                $skipped++;
            }
        });

        Inertia::flash('toast', ['type' => $skipped === 0 ? 'success' : 'warning', 'message' => $skipped === 0
            ? trans_choice(':count order updated.|:count orders updated.', $done)
            : __(':done updated, :skipped skipped (not allowed in their current status).', ['done' => $done, 'skipped' => $skipped])]);

        return back();
    }

    /**
     * Run a status change, turning a disallowed transition into an inline error.
     *
     * @param  callable(): void  $change
     */
    private function attempt(callable $change): RedirectResponse
    {
        try {
            $change();
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back();
    }
}
