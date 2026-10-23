<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OutOfRangeException;

/**
 * Nepal VAT mode (ADR 0024 §8, vat-register §3): whether the register applies, from which day, which tax rules are
 * Nepal VAT and which one marks an export. The settings are written only here, never by the generic settings form.
 *
 * Classification (`classify`) is what the fiscal snapshot stores for a document numbered while the mode is on:
 *  - export when one of its two tax rules is the export rule (the only way a supply becomes export, D6);
 *  - else taxable when a Nepal VAT level carries tax;
 *  - else exempt, which includes a document that carries only other tax (say India GST on an Indian client): that tax
 *    stays in `fiscal_tax1`/`fiscal_tax2` but is not Nepal VAT and never reaches the VAT figures.
 */
class NepalVatMode
{
    public const ENABLED = 'billing.nepal_vat.enabled';

    public const ENABLED_FROM = 'billing.nepal_vat.enabled_from';

    public const VAT_RULES = 'billing.nepal_vat.vat_rules';

    public const EXPORT_RULE = 'billing.nepal_vat.export_rule';

    /** How many BS months ahead staff can pick as the start. */
    public const MONTHS_AHEAD = 24;

    /** Settings the mode turns on or fixes at enabling; the settings form then refuses to change them. */
    public const LOCKED = [
        'billing.number_format', 'billing.number_reset', 'billing.number_padding', 'billing.number_increment',
        'billing.credit_note_format', 'billing.credit_note_padding', 'billing.credit_note_reset',
        'billing.debit_note_format', 'billing.debit_note_padding', 'billing.debit_note_reset',
        'billing.sequential_paid_numbering', 'billing.set_issue_date_on_payment',
    ];

    /** @var list<string>|null "name|rate" of the Nepal VAT rules, for classify() */
    private ?array $named = null;

    public function __construct(
        private BillingSettings $billing,
        private Settings $settings,
        private CompanyClock $clock,
        private ActivityLogger $activity,
    ) {}

    public function enabled(): bool
    {
        return $this->billing->bool('nepal_vat.enabled');
    }

    /** The first day (AD date, Y-m-d) of the BS month the mode starts in. */
    public function enabledFrom(): ?string
    {
        $value = $this->billing->string('nepal_vat.enabled_from');

        return $value !== '' ? $value : null;
    }

    /** @return list<int> */
    public function vatRules(): array
    {
        return $this->billing->intList('nepal_vat.vat_rules');
    }

    public function exportRule(): ?int
    {
        $id = $this->billing->int('nepal_vat.export_rule');

        return $id > 0 ? $id : null;
    }

    /** Whether the mode applies at an instant: switched on and the company date has reached `enabled_from`. */
    public function inEffect(CarbonInterface $at): bool
    {
        $from = $this->enabledFrom();

        return $this->enabled() && $from !== null && $this->clock->localDate($at) >= $from;
    }

    /**
     * How a document is classified, from the tax rules of its two levels and its tax amounts. A level with tax but no
     * rule id (calculated before rule ids were stored) is matched to a Nepal VAT rule by its name and rate instead;
     * `$levels` is [name1, rate1, name2, rate2] as stored on the invoice.
     *
     * @param  array{0: string|null, 1: string, 2: string|null, 3: string}|null  $levels
     * @return array{vat1: bool, vat2: bool, class: string}
     */
    public function classify(?int $rule1, ?int $rule2, int $tax1, int $tax2, ?array $levels = null): array
    {
        $rules = $this->vatRules();
        $vat1 = $rule1 !== null ? in_array($rule1, $rules, true) : ($levels !== null && $tax1 !== 0 && $this->isVatByName($levels[0], $levels[1]));
        $vat2 = $rule2 !== null ? in_array($rule2, $rules, true) : ($levels !== null && $tax2 !== 0 && $this->isVatByName($levels[2], $levels[3]));
        $export = $this->exportRule();

        return [
            'vat1' => $vat1,
            'vat2' => $vat2,
            'class' => match (true) {
                $export !== null && ($rule1 === $export || $rule2 === $export) => 'export',
                ($vat1 ? $tax1 : 0) + ($vat2 ? $tax2 : 0) !== 0 => 'taxable',
                default => 'exempt',
            },
        ];
    }

    private function isVatByName(?string $name, string $rate): bool
    {
        $this->named ??= TaxRule::query()->whereIn('id', $this->vatRules())->get(['name', 'rate'])->map(fn (TaxRule $rule): string => $rule->name.'|'.(float) $rule->rate)->all();

        return $name !== null && in_array($name.'|'.(float) $rate, $this->named, true);
    }

    /**
     * The BS months staff can start the mode in: the current one and the next ones, with the AD date each begins on.
     *
     * @return list<array{value: string, label: string}>
     */
    public function months(): array
    {
        $today = BikramSambat::fromAd($this->clock->todayDate());
        [$year, $month] = [$today->year, $today->month];
        $months = [];

        for ($i = 0; $i <= self::MONTHS_AHEAD && $year <= BikramSambat::LAST_YEAR; $i++) {
            $start = BikramSambat::toAd($year, $month, 1);
            $months[] = ['value' => $start->toDateString(), 'label' => sprintf('%s %d BS (from %s)', BikramSambat::MONTHS[$month - 1], $year, $start->format('j M Y'))];
            [$year, $month] = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
        }

        return $months;
    }

    /**
     * What stops the mode from being enabled right now, in plain words. `$deep` adds the checks that scan large
     * tables; the settings page leaves them to the save, which runs them under the lock.
     *
     * @return list<string>
     */
    public function blockers(?string $from = null, bool $deep = false): array
    {
        $blockers = [];

        if (app(FiscalBackfill::class)->hasPending()) {
            $blockers[] = __('Some numbered invoices have no tax snapshot yet. Run "php artisan billing:fiscal-snapshot" until it reports nothing left.');
        }

        if ($this->splitPending()) {
            $blockers[] = __('Some numbered invoices are not yet split into Nepal VAT and other tax. Save the tax rules below, then run "php artisan billing:fiscal-vat-split" until it reports nothing left.');
        }

        if (InvoiceFiscalReview::query()->unseen()->exists()) {
            $blockers[] = __('The tax snapshot review list has entries staff have not seen. List them with "php artisan billing:fiscal-snapshot --list".');
        }

        if ($deep && app(FiscalBackfill::class)->mismatches() > 0) {
            $blockers[] = __('Some invoices have credited or debited totals that differ from their adjustments. Run "php artisan billing:fiscal-snapshot".');
        }

        if ($deep && $from !== null && $this->oldStyleRowsFrom($from)) {
            $blockers[] = __('A cancellation, refund or late fee was recorded on a numbered invoice on or after that start date. Pick the next BS month.');
        }

        return $blockers;
    }

    /** Whether any taxed, numbered invoice still has no Nepal VAT classification (reads `invoices_vat_split_pending_index`). */
    public function splitPending(): bool
    {
        return DB::table('invoices')->whereNotNull('numbered_at')->whereNull('fiscal_tax1_is_vat')->where('kind', '<>', 'add_funds')
            ->where(fn ($query) => $query->where('tax1_amount', '<>', 0)->orWhere('tax2_amount', '<>', 0))->exists();
    }

    /**
     * Save the mode. Runs under a lock on the mode's settings row, so two staff members cannot enable it together
     * and the checks hold when the values are written. Throws a ValidationException for anything refused.
     *
     * @param  array{enabled: bool, enabled_from?: string|null, vat_rules?: list<int>, export_rule?: int|null}  $input
     * @return bool whether anything changed
     */
    public function apply(User $actor, array $input): bool
    {
        return DB::transaction(function () use ($actor, $input): bool {
            if (Setting::query()->whereKey(self::ENABLED)->lockForUpdate()->first() === null) {
                Setting::query()->insertOrIgnore(['key' => self::ENABLED, 'value' => json_encode($this->enabled())]);
                Setting::query()->whereKey(self::ENABLED)->lockForUpdate()->firstOrFail();
                $this->settings->forgetCached();
            }

            $before = $this->current();
            $after = $this->validated($input, $before);

            if ($after === $before) {
                return false;
            }

            $enabling = $after['enabled'] && ! $before['enabled'];
            $values = [
                self::ENABLED => $after['enabled'],
                self::ENABLED_FROM => $after['enabled_from'],
                self::VAT_RULES => $after['vat_rules'],
                self::EXPORT_RULE => $after['export_rule'],
            ];

            if ($enabling) {
                // Tax invoices are numbered on payment, one number at a time (vat-register §3).
                $values += ['billing.sequential_paid_numbering' => true, 'billing.set_issue_date_on_payment' => true, 'billing.number_increment' => 1];
            }

            $this->settings->setMany($values);
            $this->activity->log($enabling ? 'Nepal VAT mode enabled' : 'Nepal VAT mode changed', properties: ['from' => $before, 'to' => $after], event: $enabling ? 'billing.nepal_vat.enabled' : 'billing.nepal_vat.changed', actor: $actor);

            return true;
        });
    }

    /** @return array{enabled: bool, enabled_from: string|null, vat_rules: list<int>, export_rule: int|null} */
    public function current(): array
    {
        return ['enabled' => $this->enabled(), 'enabled_from' => $this->enabledFrom(), 'vat_rules' => $this->vatRules(), 'export_rule' => $this->exportRule()];
    }

    /**
     * @param  array{enabled: bool, enabled_from?: string|null, vat_rules?: list<int>, export_rule?: int|null}  $input
     * @param  array{enabled: bool, enabled_from: string|null, vat_rules: list<int>, export_rule: int|null}  $before
     * @return array{enabled: bool, enabled_from: string|null, vat_rules: list<int>, export_rule: int|null}
     */
    private function validated(array $input, array $before): array
    {
        $rules = array_values(array_unique(array_map('intval', $input['vat_rules'] ?? [])));
        sort($rules);
        $export = isset($input['export_rule']) && $input['export_rule'] > 0 ? (int) $input['export_rule'] : null;
        $enabled = (bool) $input['enabled'];
        $from = $input['enabled_from'] ?? null;
        $errors = [];

        $known = TaxRule::query()->whereIn('id', array_filter([...$rules, $export]))->pluck('rate', 'id');

        if (array_diff($rules, $known->keys()->all()) !== []) {
            $errors['vat_rules'] = __('One of the chosen tax rules no longer exists.');
        }

        if ($export !== null && ! $known->has($export)) {
            $errors['export_rule'] = __('The export tax rule no longer exists.');
        } elseif ($export !== null && in_array($export, $rules, true)) {
            $errors['export_rule'] = __('A tax rule cannot be both Nepal VAT and the export rule.');
        } elseif ($export !== null && (float) $known[$export] !== 0.0) {
            $errors['export_rule'] = __('The export rule must charge 0%. Add a 0% tax rule for foreign clients and choose it here.');
        }

        if ($enabled && $rules === []) {
            $errors['vat_rules'] = __('Choose at least one tax rule that is Nepal VAT.');
        }

        // Once the mode has started its start day is history; switching off keeps it.
        $from = $enabled && ! $this->started($before) ? $this->validStart($from, $errors) : $before['enabled_from'];

        if (! $enabled && $before['enabled'] && TaxNote::query()->exists()) {
            $errors['enabled'] = __('The mode cannot be switched off: credit or debit notes have been issued under it.');
        }

        if ($enabled && ! $before['enabled'] && $errors === [] && ($blockers = $this->blockers($from, deep: true)) !== []) {
            $errors['enabled'] = implode(' ', $blockers);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['enabled' => $enabled, 'enabled_from' => $from, 'vat_rules' => $rules, 'export_rule' => $export];
    }

    /** @param array{enabled: bool, enabled_from: string|null} $before */
    private function started(array $before): bool
    {
        return $before['enabled'] && $before['enabled_from'] !== null && $before['enabled_from'] <= $this->clock->localDate($this->clock->now());
    }

    /**
     * The start day must be the first day of the current BS month or of a later one (ADR 0024 §8).
     *
     * @param  array<string, string>  $errors
     */
    private function validStart(?string $from, array &$errors): ?string
    {
        try {
            $date = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? BikramSambat::fromAd(CarbonImmutable::parse($from, 'UTC')) : null;
            $today = BikramSambat::fromAd($this->clock->todayDate());
        } catch (OutOfRangeException) {
            $date = $today = null;
        }

        if ($date === null || $today === null || $date->day !== 1 || $date->year * 100 + $date->month < $today->year * 100 + $today->month) {
            $errors['enabled_from'] = __('Pick the first day of the current Nepali (BS) month or of a later one.');

            return null;
        }

        return $from;
    }

    /** Whether an old-style value adjustment was written on a numbered invoice, after its numbering, on or after the start day. */
    private function oldStyleRowsFrom(string $from): bool
    {
        [$start] = $this->clock->dayBounds($from);

        return DB::table('invoice_adjustments as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->whereIn('a.reason', array_map(fn (AdjustmentReason $reason): string => $reason->value, [AdjustmentReason::Cancellation, AdjustmentReason::ItemCancellation, AdjustmentReason::Refund, AdjustmentReason::LateFee]))
            ->whereNotNull('i.numbered_at')->whereColumn('a.created_at', '>', 'i.numbered_at')->where('a.created_at', '>=', $start)->exists();
    }
}
