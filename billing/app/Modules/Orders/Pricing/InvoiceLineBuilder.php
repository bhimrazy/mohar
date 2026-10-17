<?php

namespace App\Modules\Orders\Pricing;

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use Carbon\CarbonImmutable;

/**
 * Turns a priced cart into invoice lines. Used for the tax preview (no billable ids yet) and for the
 * order invoice (ids of the pending services/addons/domains created at checkout).
 *
 * Per line: the charge (Service | ServiceAddon | Domain), its setup fee (ServiceSetup, or ServiceAddon for
 * addon setup fees) and, when discounted, a negative Promotion line with the same taxability so the
 * discount reduces the taxable base of the line it discounts.
 */
class InvoiceLineBuilder
{
    /**
     * @param  array<string, int>  $billableIds  priced line key => created service/addon/domain id
     * @return list<InvoiceLineData>
     */
    public function build(PricedCart $cart, CarbonImmutable $today, array $billableIds = []): array
    {
        $lines = [];

        foreach ($cart->validLines() as $line) {
            array_push($lines, ...$this->linesFor($line, $today, $billableIds[$line->key] ?? null, $cart));
        }

        return $lines;
    }

    /**
     * @return list<InvoiceLineData>
     */
    private function linesFor(PricedLine $line, CarbonImmutable $today, ?int $billableId, PricedCart $cart): array
    {
        [$periodStart, $periodEnd] = $this->period($line, $today);

        $chargeType = match ($line->kind) {
            'product' => BillableType::Service,
            'addon' => BillableType::ServiceAddon,
            'domain' => BillableType::Domain,
        };

        $lines = [new InvoiceLineData(
            description: $line->kind === 'addon' ? __('Addon: :name', ['name' => $line->description]) : $line->description,
            unitAmount: $line->recurring,
            taxable: $line->taxable,
            billableType: $chargeType,
            billableId: $billableId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        )];

        if ($line->setup > 0) {
            $lines[] = new InvoiceLineData(
                description: __('Setup fee: :name', ['name' => $line->description]),
                unitAmount: $line->setup,
                taxable: $line->taxable,
                billableType: $line->kind === 'addon' ? BillableType::ServiceAddon : BillableType::ServiceSetup,
                billableId: $billableId,
            );
        }

        if ($line->totalDiscount() > 0 && $cart->promotion !== null) {
            $lines[] = new InvoiceLineData(
                description: __('Promotion :code (:summary): :name', [
                    'code' => $cart->promotion->code,
                    'summary' => $cart->promotion->summary(),
                    'name' => $line->description,
                ]),
                unitAmount: -$line->totalDiscount(),
                taxable: $line->taxable,
                billableType: BillableType::Promotion,
                billableId: $cart->promotion->id,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
        }

        return $lines;
    }

    /**
     * Service periods are half-open [today, today + cycle); one-time charges have no period.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(PricedLine $line, CarbonImmutable $today): array
    {
        if ($line->kind === 'domain') {
            return [$today, $today->addYearsNoOverflow($line->years)];
        }

        if ($line->cycle === null || ! $line->cycle->isRecurring()) {
            return [null, null];
        }

        return [$today, $line->cycle->advance($today, anchorDay: $today->day)];
    }
}
