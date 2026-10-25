<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainInvoice;
use App\Support\Billing\InvoiceNotCancellable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Renewal invoices issued by this module through Billing's Invoicer, tracked in domain_invoices so the
 * lifecycle can cancel or replace the open invoice of a cycle. Invoicer is resolved lazily: without a
 * Billing module (MODULES isolation, demo) nothing has been issued, so cancellations are no-ops.
 */
class RenewalInvoices
{
    public const OPEN_STATUSES = ['draft', 'unpaid'];

    public const RENEWAL_KINDS = ['renewal', 'grace', 'redemption'];

    private ?Currency $defaultCurrency = null;

    /** @var array<string, Currency|null> */
    private array $currencies = [];

    public function available(): bool
    {
        return app()->bound(Invoicer::class);
    }

    public function invoicer(): Invoicer
    {
        if (! $this->available()) {
            throw new RuntimeException('No Invoicer is bound; the Billing module is not loaded.');
        }

        return app(Invoicer::class);
    }

    /**
     * Unpaid renewal invoices of the domain, reconciled against Billing's current status.
     *
     * @return Collection<int, DomainInvoice>
     */
    public function open(Domain $domain): Collection
    {
        if (! $this->available()) {
            return new Collection;
        }

        return DomainInvoice::query()
            ->where('domain_id', $domain->id)
            ->where('status', 'open')
            ->whereIn('kind', self::RENEWAL_KINDS)
            ->get()
            ->filter(function (DomainInvoice $invoice): bool {
                $status = $this->invoicer()->status($invoice->invoice_id);

                if (in_array($status, self::OPEN_STATUSES, true)) {
                    return true;
                }

                $invoice->update(['status' => $status === 'paid' ? 'paid' : 'cancelled']);

                return false;
            })
            ->values();
    }

    /**
     * Cancel every open renewal invoice of the domain. Returns how many were cancelled.
     */
    public function cancelOpen(Domain $domain): int
    {
        $cancelled = 0;

        foreach ($this->open($domain) as $invoice) {
            try {
                $this->invoicer()->cancel($invoice->invoice_id, __('Cancelled by :module', ['module' => 'Domains']));
            } catch (InvoiceNotCancellable) {
                // Still payable: the renewal invoice stays open here too.
                continue;
            }

            $invoice->update(['status' => 'cancelled']);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * The invoice kind for the domain's status and its late fee in the default currency (grace and redemption only).
     *
     * @return array{0: 'renewal'|'grace'|'redemption', 1: int}
     */
    public function charge(Domain $domain): array
    {
        return match ($domain->status) {
            DomainStatus::Grace => ['grace', $domain->tldModel->grace_fee],
            DomainStatus::Redemption => ['redemption', $domain->tldModel->redemption_fee],
            default => ['renewal', 0],
        };
    }

    /**
     * The lines of a renewal invoice for the domain's current cycle (period starting next_due_date), before tax:
     * the term at the given amount, plus the grace/redemption fee converted from the default currency. Renew now's
     * confirmation sums these, so it states what issue() bills.
     *
     * @param  'renewal'|'grace'|'redemption'  $kind
     * @param  int  $feeInDefaultCurrency  minor units in the system default currency
     * @param  int|null  $years  term to bill (default: the domain's renewal period)
     * @param  int|null  $amount  renewal amount for the term (default: the locked recurring amount)
     * @return list<InvoiceLineData>
     */
    public function lines(Domain $domain, string $kind = 'renewal', int $feeInDefaultCurrency = 0, ?int $years = null, ?int $amount = null): array
    {
        $years ??= $domain->period_years;
        $periodStart = $domain->next_due_date;
        $periodEnd = $periodStart->addYearsNoOverflow($years);
        $term = $years === 1 ? '1 year' : "{$years} years";

        $lines = [new InvoiceLineData(
            description: "Domain renewal - {$domain->name_unicode} ({$term}, {$periodStart->toDateString()} to {$periodEnd->toDateString()})",
            unitAmount: $amount ?? $domain->recurring_amount,
            billableType: BillableType::Domain,
            billableId: $domain->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        )];

        if ($kind !== 'renewal' && $feeInDefaultCurrency > 0) {
            $lines[] = new InvoiceLineData(
                description: ($kind === 'grace' ? 'Late renewal (grace period) fee' : 'Redemption (restore) fee')." - {$domain->name_unicode}",
                unitAmount: $this->convertFromDefault($feeInDefaultCurrency, $domain->currency),
                billableType: BillableType::Domain,
                billableId: $domain->id,
            );
        }

        return $lines;
    }

    /**
     * Issue the renewal invoice for the domain's current cycle with lines().
     *
     * A zero-total invoice is paid immediately by Billing, so InvoicePaid may reach HandleInvoicePaid before
     * this method records the invoice; the tracking row is therefore created idempotently and a row the
     * listener already marked paid is never downgraded.
     *
     * @param  'renewal'|'grace'|'redemption'  $kind
     */
    public function issue(Domain $domain, string $kind = 'renewal', int $feeInDefaultCurrency = 0, ?int $years = null, ?int $amount = null): InvoiceResult
    {
        $years ??= $domain->period_years;
        $periodStart = $domain->next_due_date;
        $lines = $this->lines($domain, $kind, $feeInDefaultCurrency, $years, $amount);

        $result = $this->invoicer()->issue(new InvoiceDraft(
            clientId: $domain->client_id,
            currency: $domain->currency,
            lines: $lines,
            dueDate: $kind === 'renewal' ? $domain->next_due_date : null,
            paymentGateway: $domain->gateway_key,
        ));

        $record = DomainInvoice::query()->firstOrCreate(
            ['domain_id' => $domain->id, 'invoice_id' => $result->invoiceId],
            ['kind' => $kind, 'period_start' => $periodStart, 'years' => $years, 'status' => 'open'],
        );

        if (! $record->wasRecentlyCreated && $record->kind !== $kind) {
            $record->update(['kind' => $kind]);
        }

        return $result;
    }

    /**
     * Convert an amount in the default currency with the current exchange rate and the currency's rounding rule (fees are stored once).
     */
    public function convertFromDefault(int $amount, string $currency): int
    {
        // One query for the default and target currencies, memoised per instance: the dashboard converts a fee for each
        // grace or redemption row.
        if ($this->defaultCurrency === null || ! array_key_exists($currency, $this->currencies)) {
            $found = Currency::query()->where('is_default', true)->orWhere('code', $currency)->get();
            $this->defaultCurrency ??= $found->firstWhere('is_default', true) ?? Currency::default();
            $this->currencies[$currency] = $found->firstWhere('code', $currency);
        }

        $default = $this->defaultCurrency;

        if ($default->code === $currency) {
            return $amount;
        }

        $target = $this->currencies[$currency];

        return $target === null ? $amount : $target->priceFromDefault($amount, $default);
    }
}
