<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceDraft;
use App\Data\Domains\DomainDraft;
use App\Data\Services\AddonDraft;
use App\Data\Services\ServiceDraft;
use App\Enums\BillingCycle;
use App\Events\Core\ClientRegistered;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\PlacedOrder;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Fraud\FraudAssessment;
use App\Modules\Orders\Fraud\FraudContext;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Jobs\ProvisionOrderItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Models\PromotionRedemption;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Pricing\InvoiceLineBuilder;
use App\Modules\Orders\Pricing\PricedCart;
use App\Modules\Orders\Pricing\PricedItem;
use App\Modules\Orders\Pricing\PricedLine;
use App\Modules\Orders\Pricing\PromotionEligibility;
use App\Modules\Orders\Services\CheckoutGateways;
use App\Modules\Orders\Services\CompanyClock;
use App\Modules\Orders\Services\OrderNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checkout (orders.md §4): re-quote and validate the cart, then in ONE transaction create the order and
 * its items, the pending services/addons/domains, reserve stock, redeem the promotion and issue the first
 * invoice. After commit: fraud screening, OrderPlaced, then on-order provisioning.
 *
 * Idempotent per idempotency key: a replayed submit returns the first order.
 */
class PlaceOrder
{
    public function __construct(
        private CartPricer $pricer,
        private InvoiceLineBuilder $invoiceLines,
        private PriceBook $priceBook,
        private ServiceRegistry $services,
        private DomainRegistry $domains,
        private Invoicer $invoicer,
        private CheckoutGateways $gateways,
        private OrderNumberGenerator $numbers,
        private FraudScreening $fraud,
        private PromotionEligibility $eligibility,
        private MarkOrderFraud $markFraud,
        private RegisterCheckoutClient $registerClient,
        private CompanyClock $clock,
        private Settings $settings,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(CheckoutData $data): PlacedOrder
    {
        if ($data->idempotencyKey === null) {
            return $this->place($data);
        }

        try {
            return Cache::lock('orders.checkout.'.sha1($data->idempotencyKey), 30)
                ->block(10, fn (): PlacedOrder => $this->place($data));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['cart' => __('Your order is already being placed. Please wait a moment.')]);
        }
    }

    /**
     * Runs under the idempotency lock: a replayed key returns the first order instead of placing another.
     */
    private function place(CheckoutData $data): PlacedOrder
    {
        $fingerprint = $this->fingerprint($data);

        if ($existing = $this->existing($data->idempotencyKey, $fingerprint)) {
            return new PlacedOrder($existing, duplicate: true);
        }

        $this->validateGateway($data);

        // Ask the fraud provider before opening the transaction: a slow provider must never hold locks.
        $assessment = $this->fraud->assess($this->fraudContext($data), $data->skipFraud, $data->client);

        /** @var array{0: Order, 1: ?User, 2: bool, 3: FraudDecision} $result */
        $result = DB::transaction(fn (): array => $this->persist($data, $fingerprint, $assessment));

        [$order, $user, $hadActiveService, $decision] = $result;

        if ($user !== null) {
            ClientRegistered::dispatch($order->client_id, $user->id);
            event(new Registered($user));
        }

        if ($decision === FraudDecision::Fail) {
            OrderMarkedFraud::dispatch($order->id, $order->client_id);
        }

        $this->announce($order);

        if ($decision->allowsAutoProvisioning()) {
            $this->dispatchOnOrderProvisioning($order, $hadActiveService);
        }

        return new PlacedOrder($order->refresh(), registeredUser: $user);
    }

    /**
     * A replayed key returns its order only for the same checkout (same client and cart); a key reused
     * with a different cart is refused, so the new cart is never silently dropped.
     */
    private function existing(?string $key, string $fingerprint): ?Order
    {
        $order = $key === null ? null : Order::query()->where('idempotency_key', $key)->first();

        if ($order !== null && $order->idempotency_fingerprint !== $fingerprint) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('This checkout was already submitted with different details. Reload the page and try again.'),
            ]);
        }

        return $order;
    }

    /**
     * Identity of a checkout submission: who orders what, how they pay.
     */
    private function fingerprint(CheckoutData $data): string
    {
        return hash('sha256', (string) json_encode([
            $data->client->id ?? mb_strtolower($data->registration['email'] ?? ''),
            $data->currency,
            $data->paymentGateway,
            $data->promotionCode,
            $data->priceOverrides,
            array_map(fn ($item): array => [
                $item->key, $item->kind->value, $item->productId, $item->cycle?->value, $item->configOptions,
                $item->customFields, $item->domainMode, $item->domain(), $item->addonIds, $item->years,
            ], $data->items),
        ]));
    }

    /**
     * OrderPlaced for Notifications/Affiliates/Catalog; a failing synchronous listener must not stop
     * on-order provisioning (the customer would retry and get the duplicate order back).
     */
    private function announce(Order $order): void
    {
        try {
            OrderPlaced::dispatch(
                $order->id,
                $order->client_id,
                $order->invoice_id,
                array_values($order->items()->orderBy('id')->get()->map(fn (OrderItem $item): array => [
                    'type' => $item->item_type->eventType(),
                    'id' => (int) $item->item_id,
                    'product_id' => $item->product_id,
                ])->all()),
                $order->referral_code,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What the fraud provider sees before the order is stored (no order id; no client id for a sign-up).
     */
    private function fraudContext(CheckoutData $data): FraudContext
    {
        $client = $data->client;
        $currency = $client->currency ?? $data->currency;

        return new FraudContext(
            orderId: null,
            clientId: $client?->id,
            email: $client->email ?? mb_strtolower($data->registration['email'] ?? ''),
            ipAddress: $data->ipAddress,
            country: $client->country ?? ($data->registration['country'] ?? null),
            total: $this->pricer->price($data->items, $currency, $data->promotionCode, $client, $data->priceOverrides)->total(),
            currency: $currency,
        );
    }

    private function validateGateway(CheckoutData $data): void
    {
        if ($data->items === []) {
            throw ValidationException::withMessages(['cart' => __('Your cart is empty.')]);
        }

        $productIds = array_values(array_unique(array_filter(array_map(fn ($item): ?int => $item->productId, $data->items))));
        $allowed = $this->gateways->forProducts($productIds, $data->client->currency ?? $data->currency);

        if ($allowed === []) {
            throw ValidationException::withMessages([
                'payment_gateway' => __('The products in your cart cannot be paid with the same payment method. Order them separately.'),
            ]);
        }

        if (! array_key_exists($data->paymentGateway, $allowed)) {
            throw ValidationException::withMessages(['payment_gateway' => __('Choose one of the available payment methods.')]);
        }
    }

    /**
     * @return array{0: Order, 1: ?User, 2: bool, 3: FraudDecision}
     */
    private function persist(CheckoutData $data, string $fingerprint, FraudAssessment $assessment): array
    {
        $user = null;
        $client = $data->client;

        if ($client === null) {
            if ($data->registration === null) {
                throw ValidationException::withMessages(['email' => __('Log in or create an account to place your order.')]);
            }

            [$user, $client] = $this->registerClient->handle($data->registration, $data->currency);
        }

        $cart = $this->pricer->price($data->items, $client->currency, $data->promotionCode, $client, $data->priceOverrides);
        $this->assertCheckoutable($cart, $data);

        $today = $this->clock->today();
        $hadActiveService = $this->eligibility->hasActiveService($client);
        $promotion = $cart->promotionApplied() ? $cart->promotion : null;

        $order = Order::query()->create([
            'number' => $this->numbers->generate(),
            'client_id' => $client->id,
            'kind' => OrderKind::New,
            'status' => OrderStatus::Pending,
            'currency' => $cart->currency,
            'subtotal' => $cart->subtotal(),
            'discount_total' => $cart->discountTotal(),
            'setup_total' => $cart->setupTotal(),
            'tax_total' => $cart->taxTotal,
            'total' => $cart->total(),
            'promotion_id' => $promotion?->id,
            'promotion_code' => $promotion?->code,
            'payment_gateway' => $data->paymentGateway,
            'ip_address' => $data->ipAddress,
            'user_agent' => $data->userAgent === null ? null : mb_substr($data->userAgent, 0, 255),
            'referral_code' => $data->referralCode,
            'placed_by_user_id' => $user->id ?? $data->userId,
            'placed_by_staff_id' => $data->staffId,
            'notes' => $data->notes,
            'idempotency_key' => $data->idempotencyKey,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        $billableIds = [];

        foreach ($cart->items as $item) {
            $billableIds += $this->createItems($order, $client, $item, $promotion, $today);
        }

        if ($promotion !== null) {
            $this->redeem($promotion, $order, $client, $cart, $billableIds);
        }

        // Record the decision before the invoice exists: a failed check must not mail a "pay now" invoice or
        // spend credit, and a zero-total invoice (paid on issue) must find the decision when InvoicePaid is handled.
        $decision = $this->fraud->record($order, $assessment);

        if ($decision === FraudDecision::Fail) {
            $this->markFraud->flag($order);
        }

        $invoice = $this->invoicer->issue(new InvoiceDraft(
            clientId: $client->id,
            currency: $cart->currency,
            lines: $this->invoiceLines->build($cart, $today, $billableIds),
            dueDate: $today->addDays((int) $this->settings->get('orders.initial_invoice_grace_days', 0)),
            issueDate: $today,
            paymentGateway: $data->paymentGateway,
            notes: __('Order :number', ['number' => $order->number]),
            notify: $data->notifyInvoice && $decision !== FraudDecision::Fail && ! $this->settings->get('orders.suppress_invoice_email_on_order', false),
            applyCredit: $data->applyCredit && $decision->allowsAutoProvisioning(),
        ));

        $order->forceFill([
            'invoice_id' => $invoice->invoiceId,
            'tax_total' => $invoice->tax,
            'total' => $invoice->total,
        ])->save();

        return [$order, $user, $hadActiveService, $decision];
    }

    private function assertCheckoutable(PricedCart $cart, CheckoutData $data): void
    {
        foreach ($cart->items as $item) {
            if (! $item->isValid()) {
                throw ValidationException::withMessages(['cart' => $item->errors[0]]);
            }
        }

        if ($cart->promotionCode !== null && $cart->promotionError !== null) {
            throw ValidationException::withMessages(['promotion_code' => $cart->promotionError]);
        }

        if ($data->expectedNetTotal !== null && $data->expectedNetTotal !== $cart->netTotal()) {
            throw ValidationException::withMessages([
                'cart' => __('Prices in your cart have changed. Review the updated total and place your order again.'),
            ]);
        }
    }

    /**
     * Create the pending service/addons/domain of one cart item and their order items.
     *
     * @return array<string, int> priced line key => created id
     */
    private function createItems(Order $order, Client $client, PricedItem $item, ?Promotion $promotion, CarbonImmutable $today): array
    {
        $ids = [];
        $serviceItem = null;
        $serviceId = null;
        $product = $item->product;

        foreach ($item->lines as $line) {
            if ($line->kind === 'product' && $product !== null) {
                if (! $this->priceBook->reserveStock($product->id)) {
                    throw ValidationException::withMessages(['cart' => __(':product is out of stock.', ['product' => $product->name])]);
                }

                $recurringPromo = $promotion !== null && $promotion->recurring && $line->discount > 0;
                $cycle = $line->cycle ?? BillingCycle::Monthly;

                $serviceId = $this->services->createPending(new ServiceDraft(
                    clientId: $client->id,
                    productId: $product->id,
                    cycle: $cycle,
                    currency: $order->currency,
                    recurringAmount: $line->recurring,
                    firstPaymentAmount: $line->dueToday(),
                    nextDueDate: $today,
                    orderId: $order->id,
                    domain: $line->domain,
                    configOptions: $item->item->configOptions,
                    customFields: $item->item->customFields,
                    promotionId: $recurringPromo ? $promotion->id : null,
                    promotionCyclesRemaining: $recurringPromo && $promotion->recur_count > 0 ? $promotion->recur_count : null,
                    renewalDiscount: $recurringPromo ? $line->discount : 0,
                ));

                $serviceItem = $this->orderItem($order, OrderItemType::Service, $serviceId, $line, [
                    'product_id' => $product->id,
                    'auto_setup' => $product->autoSetup,
                    'stock_reserved' => $product->stock !== null,
                    'promotion_cycles_remaining' => $recurringPromo && $promotion->recur_count > 0 ? $promotion->recur_count : null,
                    'renewal_discount' => $recurringPromo ? $line->discount : 0,
                ]);
                $ids[$line->key] = $serviceId;
            } elseif ($line->kind === 'addon' && $serviceId !== null && $line->addonId !== null) {
                $addonId = $this->services->createPendingAddon(new AddonDraft(
                    serviceId: $serviceId,
                    addonId: $line->addonId,
                    cycle: $line->cycle ?? BillingCycle::Monthly,
                    recurringAmount: $line->recurring,
                    setupFee: $line->setup,
                    nextDueDate: $today,
                    orderId: $order->id,
                ));

                $this->orderItem($order, OrderItemType::Addon, $addonId, $line, ['addon_id' => $line->addonId, 'parent_id' => $serviceItem?->id]);
                $ids[$line->key] = $addonId;
            } elseif ($line->kind === 'domain' && $line->domain !== null) {
                $domainId = $this->domains->createPending(new DomainDraft(
                    clientId: $client->id,
                    domain: $line->domain,
                    action: $line->domainAction === 'transfer' ? 'transfer' : 'register',
                    years: $line->years,
                    currency: $order->currency,
                    recurringAmount: $line->renewal,
                    firstPaymentAmount: $line->dueToday(),
                    orderId: $order->id,
                    eppCode: $item->item->eppCode,
                    nameservers: $item->item->nameservers,
                    idProtection: $item->item->idProtection,
                ));

                $this->orderItem($order, OrderItemType::Domain, $domainId, $line, ['parent_id' => $serviceItem?->id]);
                $ids[$line->key] = $domainId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function orderItem(Order $order, OrderItemType $type, int $itemId, PricedLine $line, array $attributes = []): OrderItem
    {
        return $order->items()->create([
            'item_type' => $type,
            'item_id' => $itemId,
            'status' => OrderItemStatus::Pending,
            'description' => $line->description,
            'domain' => $line->domain,
            'cycle' => $line->kind === 'domain' ? null : $line->cycle,
            'years' => $line->years,
            'taxable' => $line->taxable,
            'quantity' => 1,
            'first_amount' => $line->recurring,
            'recurring_amount' => $line->renewal,
            'setup_fee' => $line->setup,
            'discount_amount' => $line->totalDiscount(),
            ...$attributes,
        ]);
    }

    /**
     * Count the use atomically (WHERE uses < max_uses guards the race) and record a redemption per
     * discounted item.
     *
     * @param  array<string, int>  $billableIds
     */
    private function redeem(Promotion $promotion, Order $order, Client $client, PricedCart $cart, array $billableIds): void
    {
        $updated = Promotion::query()
            ->whereKey($promotion->id)
            ->when($promotion->max_uses > 0, fn ($query) => $query->where('uses', '<', $promotion->max_uses))
            ->increment('uses');

        if ($updated === 0) {
            throw ValidationException::withMessages(['promotion_code' => __('Usage limit reached: this promotion code can no longer be used.')]);
        }

        // The increment holds the promotion row lock until commit, so a concurrent checkout by the same
        // client waits here and then sees this one's redemption (READ COMMITTED) - re-check the client rules.
        if ($promotion->once_per_client && PromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->where('client_id', $client->id)
            ->whereNull('released_at')
            ->exists()) {
            throw ValidationException::withMessages(['promotion_code' => __('You have already used this promotion code.')]);
        }

        if ($promotion->new_clients_only && Order::query()
            ->where('client_id', $client->id)
            ->whereKeyNot($order->id)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->exists()) {
            throw ValidationException::withMessages(['promotion_code' => __('This promotion code is only for new customers.')]);
        }

        foreach ($cart->validLines() as $line) {
            if ($line->totalDiscount() === 0) {
                continue;
            }

            PromotionRedemption::query()->create([
                'promotion_id' => $promotion->id,
                'client_id' => $client->id,
                'order_id' => $order->id,
                'item_type' => match ($line->kind) {
                    'product' => OrderItemType::Service->value,
                    'addon' => OrderItemType::Addon->value,
                    'domain' => OrderItemType::Domain->value,
                },
                'item_id' => $billableIds[$line->key] ?? null,
                'discount_amount' => $line->totalDiscount(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Queue "create" for services whose product auto-sets up on order. With
     * orders.auto_provision_only_existing_clients, new clients' items wait for staff acceptance.
     */
    private function dispatchOnOrderProvisioning(Order $order, bool $hadActiveService): void
    {
        if ($this->settings->get('orders.auto_provision_only_existing_clients', false) && ! $hadActiveService) {
            return;
        }

        $order->items()
            ->where('item_type', OrderItemType::Service)
            ->where('auto_setup', 'order')
            ->each(fn (OrderItem $item) => ProvisionOrderItem::dispatch($item->id)->afterCommit());
    }
}
