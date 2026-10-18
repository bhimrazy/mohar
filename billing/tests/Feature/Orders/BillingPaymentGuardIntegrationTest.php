<?php

use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\SetOrderPending;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Fraud\FraudContext;
use App\Modules\Orders\Fraud\FraudResult;
use App\Modules\Orders\Fraud\RulesFraudChecker;
use Tests\Fakes\Orders\OrdersFakes;

/*
 * AC 9/10 with the real Billing module: checkout issues the invoice through Billing's Invoicer, and
 * Billing refuses a payment while the order is flagged as fraud (it consults Orders' PaymentGuard).
 */

beforeEach(function () {
    Currency::factory()->create();
});

it('refuses payment of a fraud-flagged order invoice until the order is set back to pending', function () {
    $fakes = OrdersFakes::bind();
    app()->forgetInstance(Invoicer::class);
    app()->forgetInstance(TaxCalculator::class);
    $fakes->catalog->addProduct(1);
    app()->instance(RulesFraudChecker::class, new class extends RulesFraudChecker
    {
        public function __construct() {}

        public function check(FraudContext $context): FraudResult
        {
            return new FraudResult(FraudDecision::Fail, 100, ['Test decision.']);
        }
    });

    $client = Client::factory()->create();
    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($client, [OrdersFakes::productItem(1)]))->order;
    $invoice = Invoice::query()->findOrFail($order->invoice_id);

    expect($order->status)->toBe(OrderStatus::Fraud)
        ->and($invoice->client_id)->toBe($client->id)
        ->and($invoice->total)->toBe($order->total);

    expect(fn () => app(ApplyPayment::class)->handle($invoice, new PaymentData($invoice->total, 'manual')))
        ->toThrow(BillingException::class, 'This order is under review. Payment will be possible once the review is complete.');

    app(SetOrderPending::class)->handle($order);
    app(ApplyPayment::class)->handle($invoice->fresh(), new PaymentData($invoice->total, 'manual'));

    expect(app(Invoicer::class)->status($invoice->id))->toBe('paid');
});
