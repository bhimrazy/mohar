<?php

/*
 * DescribesManualRefund (#491): Billing shows a driver's own refund note on the invoice page and the gateway form, only
 * for a driver without a refund API. Uses test doubles, so it runs without the PaymentsNepal module.
 */

use App\Contracts\Billing\DescribesManualRefund;
use App\Contracts\Billing\GatewayRegistry;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Support\Billing\AbstractGatewayDriver;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/** A driver with a refund note and, when $refunds, a refund API that makes the note moot. */
function manualRefundDriver(string $key, bool $refunds): AbstractGatewayDriver
{
    return new class($key, $refunds) extends AbstractGatewayDriver implements DescribesManualRefund
    {
        public function __construct(private string $driverKey, private bool $refunds) {}

        public function key(): string
        {
            return $this->driverKey;
        }

        public function name(): string
        {
            return 'Bank rail';
        }

        public function capabilities(): array
        {
            return $this->refunds ? [self::REFUND] : [];
        }

        public function settingsFields(): array
        {
            return [];
        }

        public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
        {
            throw new LogicException('Not used.');
        }

        public function manualRefundNote(): string
        {
            return 'Refund by bank transfer, then record it here.';
        }
    };
}

test('the invoice page and the gateway form carry the note only for a driver without a refund API', function () {
    app(GatewayRegistry::class)->register(manualRefundDriver('bank_rail', refunds: false));
    app(GatewayRegistry::class)->register(manualRefundDriver('bank_rail_api', refunds: true));
    $invoice = issueInvoice(billingClient(), [10_000]);
    billingStaff();

    $this->get(route('admin.billing.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('gateways', fn ($gateways) => collect($gateways)->firstWhere('value', 'bank_rail')['refund_note'] === 'Refund by bank transfer, then record it here.'
            && collect($gateways)->firstWhere('value', 'bank_rail_api')['refund_note'] === null
            && collect($gateways)->firstWhere('value', 'bank_transfer')['refund_note'] === null)
        ->etc());
    $this->get(route('admin.billing.gateways.edit', 'bank_rail'))->assertInertia(fn (Assert $page) => $page
        ->where('gateway.refund_note', 'Refund by bank transfer, then record it here.')->etc());
});
