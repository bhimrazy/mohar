<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Client;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ~90 days of realistic, deterministic billing history (dashboard income chart, recent payments, overdue lists).
 *
 * Everything goes through the Billing actions under a simulated clock, so numbering, ledgers, tax and dates are
 * consistent: invoices are issued on their day (weekday-weighted), most are paid a few days later, some partly,
 * a few late, refunded or cancelled, and the rest stay unpaid (several overdue).
 */
class BillingHistorySeeder extends DemoSeeder
{
    private const DAYS = 90;

    /** Monthly plan prices in minor units per currency. */
    private const PLANS = [
        'USD' => [['Starter Hosting', 999], ['Business Hosting', 2_499], ['VPS Pro', 4_900], ['Dedicated Server', 12_900], ['Managed WordPress', 1_900]],
        'INR' => [['Starter Hosting', 49_900], ['Business Hosting', 149_900], ['VPS Pro', 399_900]],
        'NPR' => [['Starter Hosting', 99_900], ['Business Hosting', 250_000], ['VPS Pro', 650_000]],
    ];

    private int $billable = 10_000;

    private int $reference = 0;

    public function run(Invoicer $invoicer, ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund): void
    {
        // Demo data only: never fabricate billing history in a live install.
        if (app()->isProduction()) {
            return;
        }

        $previous = CarbonImmutable::getTestNow();
        $today = CarbonImmutable::today();
        mt_srand(2026);

        $clients = $this->clients();
        $events = $this->plan($clients, $today);

        try {
            // One transaction for the whole history: the actions' own transactions become savepoints (fast seeding).
            DB::transaction(fn () => $this->replay($events, $invoicer, $pay, $cancel, $refund));
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($previous);
            mt_srand();
        }
    }

    /**
     * @param  list<array{at: CarbonImmutable, type: string, invoice: int, client: Client, plan: array{0: string, 1: int}, share: int}>  $events
     */
    private function replay(array $events, Invoicer $invoicer, ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund): void
    {
        /** @var array<int, Invoice> $invoices */
        $invoices = [];

        foreach ($events as $event) {
            $this->clock($event['at']);
            $invoiceKey = $event['invoice'];

            match ($event['type']) {
                'issue' => $invoices[$invoiceKey] = $this->issue($invoicer, $event['client'], $event['plan'], $event['at']),
                'pay' => $this->pay($pay, $invoices[$invoiceKey]->fresh() ?? $invoices[$invoiceKey], $event['share']),
                'refund' => $this->refund($refund, $invoices[$invoiceKey]),
                default => $cancel->handle($invoices[$invoiceKey], note: 'Customer cancelled the order', strict: false),
            };
        }
    }

    /** Fixed US customers so the history is identical on every run (Core's demo clients are random). */
    private const US_CUSTOMERS = [
        ['Olivia', 'Bennett', 'Northwind Labs', 'CA'],
        ['Marcus', 'Reid', 'Bluefin Analytics', 'TX'],
        ['Hannah', 'Cole', null, 'WA'],
        ['Diego', 'Alvarez', 'Copperleaf Studio', 'NY'],
        ['Grace', 'Kim', 'Summit Outdoor Co', 'CO'],
        ['Ethan', 'Brooks', null, 'FL'],
    ];

    /**
     * @return list<Client>
     */
    private function clients(): array
    {
        $usd = array_map(fn (array $customer): Client => Client::query()->firstOrCreate(
            ['email' => strtolower($customer[0].'.'.$customer[1]).'@example.com'],
            ['first_name' => $customer[0], 'last_name' => $customer[1], 'company_name' => $customer[2], 'address_1' => '100 Market Street',
                'city' => 'Springfield', 'state' => $customer[3], 'postcode' => '10001', 'country' => 'US', 'currency' => 'USD',
                'language' => 'en', 'status' => 'active', 'tax_exempt' => false],
        ), self::US_CUSTOMERS);
        $markets = array_map(fn (array $market): Client => $market['client'], BillingSeeder::marketClients());

        // USD customers dominate (default-currency income chart), with Indian and Nepali customers in the mix.
        return [...$usd, ...$usd, ...$markets];
    }

    /**
     * Chronological event list: issue, (partial) payments, refunds and cancellations.
     *
     * @param  list<Client>  $clients
     * @return list<array{at: CarbonImmutable, type: string, invoice: int, client: Client, plan: array{0: string, 1: int}, share: int}>
     */
    private function plan(array $clients, CarbonImmutable $today): array
    {
        $events = [];
        $key = 0;

        for ($daysAgo = self::DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $count = $day->isWeekend() ? mt_rand(0, 1) : mt_rand(1, 4);

            for ($n = 0; $n < $count; $n++) {
                $client = $clients[mt_rand(0, count($clients) - 1)];
                $plans = self::PLANS[$client->currency] ?? self::PLANS['USD'];
                $plan = $plans[mt_rand(0, count($plans) - 1)];
                $issuedAt = $day->setTime(mt_rand(9, 17), mt_rand(0, 59));
                $base = ['invoice' => ++$key, 'client' => $client, 'plan' => $plan, 'share' => 100];
                $events[] = ['at' => $issuedAt, 'type' => 'issue', ...$base];

                $roll = mt_rand(1, 100);
                $paidAt = $this->businessDay($issuedAt->addDays(mt_rand(0, 9))->setTime(mt_rand(8, 20), mt_rand(0, 59)));
                $paidAt = $paidAt->lte($issuedAt) ? $issuedAt->addMinutes(mt_rand(20, 180)) : $paidAt;

                if ($roll <= 72 && $paidAt->lt($today)) {
                    $events[] = ['at' => $paidAt, 'type' => 'pay', ...$base];

                    if (mt_rand(1, 100) <= 4 && $paidAt->addDays(3)->lt($today)) {
                        $events[] = ['at' => $paidAt->addDays(mt_rand(2, 6)), 'type' => 'refund', ...$base];
                    }
                } elseif ($roll <= 80 && $paidAt->lt($today)) {
                    $events[] = ['at' => $paidAt, 'type' => 'pay', ...$base, 'share' => 50];
                } elseif ($roll <= 84 && $issuedAt->addDays(5)->lt($today)) {
                    $events[] = ['at' => $issuedAt->addDays(mt_rand(1, 5)), 'type' => 'cancel', ...$base];
                } elseif ($roll <= 92 && $issuedAt->addDays(25)->lt($today)) {
                    // Late payer: after the due date.
                    $events[] = ['at' => $this->businessDay($issuedAt->addDays(mt_rand(16, 25))), 'type' => 'pay', ...$base];
                }
                // Otherwise unpaid: overdue once past its due date.
            }
        }

        usort($events, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['invoice']] <=> [$b['at']->getTimestamp(), $b['invoice']]);

        return $events;
    }

    /**
     * @param  array{0: string, 1: int}  $plan
     */
    private function issue(Invoicer $invoicer, Client $client, array $plan, CarbonImmutable $at): Invoice
    {
        $start = $at->startOfDay();
        $result = $invoicer->issue(new InvoiceDraft(
            clientId: $client->id,
            currency: $client->currency,
            lines: [new InvoiceLineData($plan[0], $plan[1], billableType: BillableType::Service, billableId: ++$this->billable, periodStart: $start, periodEnd: $start->addMonth())],
            dueDate: $start->addDays(14),
            paymentGateway: $this->gatewayFor($client),
            notify: false,
            reason: 'renewal',
        ));

        return Invoice::query()->findOrFail($result->invoiceId);
    }

    private function pay(ApplyPayment $pay, Invoice $invoice, int $share): void
    {
        if (! $invoice->status->isPayable() || $invoice->balance <= 0) {
            return;
        }

        $gateway = $invoice->payment_gateway ?? 'bank_transfer';
        $prefix = $gateway === 'bank_transfer' ? ($invoice->currency === 'INR' ? 'NEFT' : 'WIRE') : 'ch';
        $amount = $share === 100 ? $invoice->balance : intdiv($invoice->balance * $share, 100);

        $pay->handle($invoice, new PaymentData($amount, $gateway, sprintf('%s-hist-%05d', $prefix, ++$this->reference), fee: $gateway === 'bank_transfer' ? 0 : intdiv($amount * 29, 1000) + 30));
    }

    private function refund(RefundTransaction $refund, Invoice $invoice): void
    {
        $payment = $invoice->transactions()->where('type', 'payment')->where('refundable_remaining', '>', 0)->first();

        if ($payment !== null) {
            $refund->handle($payment, max(1, intdiv($payment->refundable_remaining * mt_rand(20, 50), 100)), RefundMode::RecordOnly, reason: 'Goodwill refund (service credit)');
        }
    }

    private function gatewayFor(Client $client): string
    {
        if ($client->currency !== 'USD' || ! BillingSeeder::sandboxAllowed()) {
            return 'bank_transfer';
        }

        return mt_rand(0, 2) === 0 ? 'bank_transfer' : SandboxCardDriver::KEY;
    }

    private function businessDay(CarbonImmutable $at): CarbonImmutable
    {
        return $at->isWeekend() ? $at->next(CarbonImmutable::MONDAY)->setTime($at->hour, $at->minute) : $at;
    }

    private function clock(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
