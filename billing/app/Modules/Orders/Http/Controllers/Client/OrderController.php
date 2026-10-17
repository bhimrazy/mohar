<?php

namespace App\Modules\Orders\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\InvoiceLinks;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private ClientContext $context,
        private InvoiceLinks $links,
    ) {}

    public function index(): Response
    {
        $this->context->authorize('billing');

        $orders = Order::query()
            ->where('client_id', $this->context->id())
            ->with('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('client/orders/index', [
            'orders' => OrderResource::collection($orders),
            'invoice_urls' => $orders->getCollection()->mapWithKeys(fn (Order $order): array => [$order->id => $this->links->client($order->invoice_id)]),
        ]);
    }

    public function show(int $order): Response
    {
        $this->context->authorize('billing');

        $model = Order::query()->where('client_id', $this->context->id())->with('items')->findOrFail($order);

        return Inertia::render('client/orders/show', [
            'order' => new OrderResource($model),
            'invoice_url' => $this->links->client($model->invoice_id),
        ]);
    }
}
