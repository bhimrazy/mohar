<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Contracts\Billing\Invoicer;
use App\Contracts\Core\CompanyClock;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Two years of realistic, deterministic billing history (dashboard income chart with its year-over-year comparison,
 * recent payments, overdue lists). Volume grows gently over the period, and the last 90 days are the busiest.
 *
 * Everything goes through the Billing actions under a simulated clock, so numbering, ledgers, tax and dates are
 * consistent: invoices are issued on their day (weekday-weighted), most are paid a few days later, some partly,
 * a few late, refunded or cancelled, and the rest stay unpaid (several overdue).
 */
class BillingHistorySeeder extends DemoSeeder
{
    private const DAYS = 730;

    /** Days at full volume; older days thin out linearly to about a third. */
    private const RECENT_DAYS = 90;

    /** Monthly plan prices in minor units per currency. */
    private const PLANS = [
        'USD' => [['Starter Hosting', 999], ['Business Hosting', 2_499], ['VPS Pro', 4_900], ['Dedicated Server', 12_900], ['Managed WordPress', 1_900]],
        'INR' => [['Starter Hosting', 49_900], ['Business Hosting', 149_900], ['VPS Pro', 399_900]],
        'NPR' => [['Starter Hosting', 99_900], ['Business Hosting', 250_000], ['VPS Pro', 650_000]],
    ];

    private int $billable = 10_000;

    private int $reference = 0;

    /** @var list<string> Nepal wallets with a seeded, active gateway row; NPR customers pay with these or by bank transfer. */
    private array $wallets = [];

    /**
     * @param  list<array{daysAgo: int, hour: int, run: \Closure(): void}>  $scenario  steps replayed on their own day inside the
     *                                                                                 history, so a scripted invoice is numbered and dated in order
     */
    public function run(Invoicer $invoicer, ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund, array $scenario = []): void
    {
        // Demo data only: never fabricate billing history in a live install.
        if (app()->isProduction()) {
            return;
        }

        $previous = CarbonImmutable::getTestNow();
        $today = app(CompanyClock::class)->today();
        mt_srand(2026);

        $this->wallets = array_values(array_map(strval(...), Gateway::query()->whereIn('key', BillingSeeder::NEPAL_WALLETS)->where('is_active', true)->orderBy('key')->pluck('key')->all()));
        $clients = $this->clients();
        $events = $this->plan($clients, $today, $scenario);

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
     * @param  list<array<string, mixed>>  $events
     */
    private function replay(array $events, Invoicer $invoicer, ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund): void
    {
        /** @var array<int, Invoice> $invoices */
        $invoices = [];

        foreach ($events as $event) {
            $this->clock($event['at']);
            $invoiceKey = $event['invoice'];

            match ($event['type']) {
                'script' => $event['run'](),
                'issue' => $invoices[$invoiceKey] = $this->issue($invoicer, $event['client'], $event['plan'], $event['at']),
                'pay' => $this->pay($pay, $invoices[$invoiceKey]->fresh() ?? $invoices[$invoiceKey], $event['share']),
                'refund' => $this->refund($refund, $invoices[$invoiceKey]),
                default => $cancel->handle($invoices[$invoiceKey], note: 'Customer cancelled the order', strict: false),
            };
        }
    }

    /** Fixed Nepali customers (generated names; PAN only where the buyer is a company) so the history is identical on every run. */
    private const NEPAL_CUSTOMERS = [
        ['Sunita', 'Shrestha', 'Shrestha Traders Pvt. Ltd.', 'Kathmandu', 'Bagmati', '44600', '301876543'],
        ['Bishal', 'Thapa', 'Everest Digital Pvt. Ltd.', 'Lalitpur', 'Bagmati', '44700', '302765432'],
        ['Mina', 'Gurung', null, 'Pokhara', 'Gandaki', '33700', null],
        ['Rabin', 'Maharjan', 'Newa Foods Pvt. Ltd.', 'Bhaktapur', 'Bagmati', '44800', '303654321'],
    ];

    /**
     * @return list<Client>
     */
    private function clients(): array
    {
        // Demo clients count as staff-created (origin is not fillable).
        $create = fn (array $customer, array $extra): Client => Client::unguarded(fn (): Client => Client::query()->firstOrCreate(
            ['email' => strtolower($customer[0].'.'.$customer[1]).'@example.com'],
            ['first_name' => $customer[0], 'last_name' => $customer[1], 'company_name' => $customer[2], 'language' => 'en', 'status' => 'active',
                'tax_exempt' => false, 'origin' => ClientOrigin::Staff, 'email_source' => EmailSource::Staff, ...$extra],
        ));
        $nepal = array_map(fn (array $customer): Client => $create($customer, [
            'address_1' => 'Main Road '.(strlen($customer[1]) + 3), 'city' => $customer[3], 'state' => $customer[4], 'postcode' => $customer[5],
            'country' => 'NP', 'currency' => 'NPR', 'tax_id' => $customer[6],
        ]), self::NEPAL_CUSTOMERS);
        // The one US customer (Core seeds the same address, so this finds it).
        $us = $create(['Olivia', 'Bennett', 'Northwind Labs'], [
            'address_1' => '100 Market Street', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'currency' => 'USD',
        ]);
        $markets = array_map(fn (array $market): Client => $market['client'], BillingSeeder::marketClients());

        // Nepali customers dominate, with a few Indian ones and a single US customer so the other currencies still show.
        return [...$nepal, ...$nepal, ...$nepal, $us, ...$markets];
    }

    /**
     * Chronological event list: issue, (partial) payments, refunds and cancellations.
     *
     * @param  list<Client>  $clients
     * @param  list<array{daysAgo: int, hour: int, run: \Closure(): void}>  $scenario
     * @return list<array<string, mixed>>
     */
    private function plan(array $clients, CarbonImmutable $today, array $scenario = []): array
    {
        $events = [];
        $key = 0;

        for ($daysAgo = self::DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $count = $day->isWeekend() ? mt_rand(0, 1) : mt_rand(1, 4);

            // Older history: the business was smaller, so keep each invoice with a probability that grows towards today.
            if ($daysAgo > self::RECENT_DAYS) {
                $keep = 35 + (int) (55 * (self::DAYS - $daysAgo) / (self::DAYS - self::RECENT_DAYS));
                $count = count(array_filter(array_fill(0, $count, 0), fn (): bool => mt_rand(1, 100) <= $keep));
            }

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
                } elseif ($daysAgo > 120 && ($roll <= 80 || $roll > 84)) {
                    // Old invoices are settled by now (late payers caught up), so overdue stays realistic.
                    $events[] = ['at' => $this->businessDay($issuedAt->addDays(mt_rand(16, 45))), 'type' => 'pay', ...$base];
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

        foreach ($scenario as $step) {
            $events[] = ['at' => $today->subDays($step['daysAgo'])->setTime($step['hour'], 0), 'type' => 'script', 'invoice' => ++$key, 'run' => $step['run']];
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
        $prefix = match ($gateway) {
            'bank_transfer' => $invoice->currency === 'INR' ? 'NEFT' : 'WIRE',
            'esewa' => 'ESW',
            'khalti' => 'KHL',
            default => 'ch',
        };
        $amount = $share === 100 ? $invoice->balance : intdiv($invoice->balance * $share, 100);

        $pay->handle($invoice, new PaymentData($amount, $gateway, sprintf('%s-hist-%05d', $prefix, ++$this->reference), fee: $gateway === SandboxCardDriver::KEY ? intdiv($amount * 29, 1000) + 30 : 0));
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
        if ($client->currency === 'NPR' && $this->wallets !== []) {
            return mt_rand(0, 3) === 0 ? 'bank_transfer' : $this->wallets[mt_rand(0, count($this->wallets) - 1)];
        }

        if ($client->currency !== 'USD' || ! BillingSeeder::sandboxAllowed()) {
            return 'bank_transfer';
        }

        return mt_rand(0, 2) === 0 ? 'bank_transfer' : SandboxCardDriver::KEY;
    }

    private function businessDay(CarbonImmutable $at): CarbonImmutable
    {
        return $at->isWeekend() ? $at->next(CarbonImmutable::MONDAY)->setTime($at->hour, $at->minute) : $at;
    }

    /**
     * Planned times are company-local (the demo company's day and hour), so each document is dated on its planned day.
     */
    private function clock(CarbonImmutable $at): void
    {
        $instant = CarbonImmutable::parse($at->format('Y-m-d H:i:s'), app(CompanyClock::class)->timezone())->utc();
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }
}
