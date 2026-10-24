<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Actions\Settings\UpdateSettings;
use App\Modules\Platform\Services\SettingsCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| Nepal VAT mode (ADR 0024 §8, vat-register §3, issue #430): the settings, the tax-rule mapping and the supply class the
| snapshot stores. 20 Oct 2026 is 3 Kartik 2083 BS; Kartik began on 18 Oct 2026 and Mangsir begins on 17 Nov 2026.
*/

beforeEach(function () {
    config(['features.billing.nepal_vat_mode' => true]);
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    $this->vat = TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
    $this->levy = TaxRule::factory()->create(['name' => 'Levy', 'level' => 2, 'country' => 'NP', 'rate' => '2.000']);
    $this->export = TaxRule::factory()->create(['name' => 'Export', 'level' => 1, 'country' => null, 'rate' => '0.000']);
    $this->gst = TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'country' => 'IN', 'rate' => '18.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function vatModeClient(string $country = 'NP', string $currency = 'NPR'): Client
{
    return Client::factory()->create(['country' => $country, 'currency' => $currency]);
}

/** @param array<string, mixed> $overrides */
function vatModePayload(array $overrides = []): array
{
    return ['enabled' => true, 'enabled_from' => '2026-11-17', 'vat_rules' => [test()->vat->id], 'export_rule' => null, ...$overrides];
}

function vatModeSwitchOn(array $overrides = []): void
{
    billingSettings(['nepal_vat.enabled' => true, 'nepal_vat.enabled_from' => '2026-10-18', 'nepal_vat.vat_rules' => [test()->vat->id], 'nepal_vat.export_rule' => test()->export->id, ...$overrides]);
}

/** Choose the VAT rules while the mode is off and classify the invoices numbered so far, as staff do before enabling. */
function vatModeSplit(): void
{
    billingSettings(['nepal_vat.vat_rules' => [test()->vat->id]]);
    app(Kernel::class)->call('billing:fiscal-vat-split');
}

test('the mode is off by default and a document is then classified by its tax sum, with no Nepal VAT flags', function () {
    $invoice = issueInvoice(vatModeClient(), [10_000])->fresh();

    expect($invoice)->supply_class->toBe('taxable')->fiscal_tax1_is_vat->toBeNull()->fiscal_tax2_is_vat->toBeNull()
        ->tax1_rule_id->toBe($this->vat->id)->tax2_rule_id->toBe($this->levy->id)
        ->and(app(NepalVatMode::class)->enabled())->toBeFalse();
});

test('enabling needs billing.tax.manage and a recent password, and nothing is saved without them', function () {
    actingAsStaff(['billing.invoices.view']);
    confirmPassword();
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertForbidden();

    actingAsStaff(['billing.tax.manage']);
    $this->withSession(['auth.password_confirmed_at' => time() - 7200])
        ->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertSessionHasErrors('password_confirmation');

    expect(app(NepalVatMode::class)->enabled())->toBeFalse()->and(ActivityLog::query()->where('event', 'like', 'billing.nepal_vat.%')->exists())->toBeFalse();
});

test('enabling saves the mode, turns proforma numbering on with increment 1 and writes one activity entry', function () {
    inCompanyTimezone('Asia/Kathmandu');
    $staff = actingAsStaff(['billing.tax.manage']);
    confirmPassword();
    billingSettings(['sequential_paid_numbering' => false, 'set_issue_date_on_payment' => false, 'number_increment' => 5]);

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['export_rule' => $this->export->id]))->assertRedirect(route('admin.billing.vat-mode.edit'));

    $mode = app(NepalVatMode::class);
    $entry = ActivityLog::query()->where('event', 'billing.nepal_vat.enabled')->sole();

    expect($mode->enabled())->toBeTrue()->and($mode->enabledFrom())->toBe('2026-11-17')->and($mode->vatRules())->toBe([$this->vat->id])->and($mode->exportRule())->toBe($this->export->id)
        ->and(app(BillingSettings::class))
        ->bool('sequential_paid_numbering')->toBeTrue()->bool('set_issue_date_on_payment')->toBeTrue()->int('number_increment')->toBe(1)
        ->and($entry->user_id)->toBe($staff->id)->and($entry->properties['from']['enabled'])->toBeFalse()->and($entry->properties['to']['enabled'])->toBeTrue();
    expect($mode->inEffect(CarbonImmutable::parse('2026-11-16 18:14:59 UTC')))->toBeFalse()
        ->and($mode->inEffect(CarbonImmutable::parse('2026-11-16 18:15:00 UTC')))->toBeTrue();
});

test('the start must be the first day of the current or a later BS month', function (string $from) {
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => $from]))->assertSessionHasErrors('enabled_from');

    expect(app(NepalVatMode::class)->enabled())->toBeFalse();
})->with(['mid-month' => '2026-11-18', 'last month' => '2026-09-18', 'not a BS month start' => '2026-11-01']);

test('the current BS month is a valid start', function () {
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => '2026-10-18']))->assertSessionHasNoErrors();

    expect(app(NepalVatMode::class)->enabledFrom())->toBe('2026-10-18');
});

test('the rules are checked: at least one VAT rule, the export rule is 0% and not also VAT', function () {
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['vat_rules' => []]))->assertSessionHasErrors('vat_rules');
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['export_rule' => $this->gst->id]))->assertSessionHasErrors('export_rule');
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['vat_rules' => [$this->export->id], 'export_rule' => $this->export->id]))->assertSessionHasErrors('export_rule');
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['vat_rules' => [999_999]]))->assertSessionHasErrors('vat_rules');

    expect(app(NepalVatMode::class)->enabled())->toBeFalse();
});

test('rules can be saved while the mode is off', function () {
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled' => false, 'enabled_from' => null, 'vat_rules' => [$this->vat->id, $this->levy->id]]))->assertSessionHasNoErrors();

    $mode = app(NepalVatMode::class);

    expect($mode->enabled())->toBeFalse()->and($mode->vatRules())->toBe([$this->vat->id, $this->levy->id])
        ->and(ActivityLog::query()->where('event', 'billing.nepal_vat.changed')->count())->toBe(1);
});

test('enabling is refused while the review list has an unseen entry, and again once it is seen', function () {
    $invoice = issueInvoice(vatModeClient(), [10_000]);
    InvoiceFiscalReview::query()->create(['invoice_id' => $invoice->id, 'reason' => InvoiceFiscalReview::LINE_LESS_ROW, 'subject' => 'invoice', 'detail' => 'x']);
    vatModeSplit();
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertSessionHasErrors('enabled');
    expect(app(NepalVatMode::class)->enabled())->toBeFalse();

    InvoiceFiscalReview::query()->update(['seen_at' => now()]);
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertSessionHasNoErrors();
    expect(app(NepalVatMode::class)->enabled())->toBeTrue();
});

test('enabling is refused while a numbered invoice is not yet split into Nepal VAT and other tax', function () {
    $invoice = issueInvoice(vatModeClient(), [10_000]);
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertSessionHasErrors('enabled');
    expect(app(NepalVatMode::class)->enabled())->toBeFalse();

    vatModeSplit();
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertSessionHasNoErrors();

    expect(app(NepalVatMode::class)->enabled())->toBeTrue()->and($invoice->fresh()->fiscal_tax1_is_vat)->toBeTrue();
});

test('enabling is refused when a cancellation, refund or late fee was written on a numbered invoice on or after the start day', function () {
    $invoice = issueInvoice(vatModeClient(), [10_000]);
    vatModeSplit();
    CarbonImmutable::setTestNow('2026-10-21 06:00:00');
    DB::table('invoice_adjustments')->insert([
        'invoice_id' => $invoice->id, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => 500, 'taxable_amount' => 500, 'tax_amount' => 0,
        'description' => 'Late fee', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoices')->where('id', $invoice->id)->update(['debited_total' => 500]);
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => '2026-10-18']))->assertSessionHasErrors('enabled');
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => '2026-11-17']))->assertSessionHasNoErrors();

    expect(app(NepalVatMode::class)->enabledFrom())->toBe('2026-11-17');
});

test('the start day can move until the mode has started, then it is fixed; the mode cannot be switched off once a note exists', function () {
    inCompanyTimezone('Asia/Kathmandu');
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();
    [, $first, $second, $third] = array_column(app(NepalVatMode::class)->months(), 'value');
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => $first]));
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => $second]))->assertSessionHasNoErrors();
    expect(app(NepalVatMode::class)->enabledFrom())->toBe($second);

    CarbonImmutable::setTestNow(CarbonImmutable::parse($second, 'Asia/Kathmandu')->addHours(1));
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled_from' => $third]))->assertSessionHasNoErrors();
    expect(app(NepalVatMode::class)->enabledFrom())->toBe($second);

    billingSettings(['sequential_paid_numbering' => false]);
    $invoice = issueInvoice(vatModeClient(), [10_000])->fresh(['items']);
    app(TaxNoteIssuer::class)->issue($request = NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Goodwill'), ($preview = app(TaxNoteIssuer::class)->preview($request))->version, $preview->digest);
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload(['enabled' => false]))->assertSessionHasErrors('enabled');

    expect(app(NepalVatMode::class)->enabled())->toBeTrue();
});

test('a document with Nepal VAT is taxable and its VAT level is flagged apart from other tax', function () {
    vatModeSwitchOn();
    $invoice = issueInvoice(vatModeClient(), [10_000])->fresh();

    // 13% VAT (level 1) and a 2% levy (level 2).
    expect($invoice)->supply_class->toBe('taxable')->fiscal_tax1->toBe(1_300)->fiscal_tax2->toBe(200)
        ->fiscal_tax1_is_vat->toBeTrue()->fiscal_tax2_is_vat->toBeFalse();
});

test('a supply is an export only through the export rule, whatever the currency', function () {
    vatModeSwitchOn();
    $abroad = issueInvoice(vatModeClient('US', 'USD'), [10_000])->fresh();
    $nepali = issueInvoice(vatModeClient('NP', 'USD'), [10_000])->fresh();

    expect($abroad)->supply_class->toBe('export')->fiscal_tax1->toBe(0)->tax1_rule_id->toBe($this->export->id)
        ->and($nepali->supply_class)->toBe('taxable');

    vatModeSwitchOn(['nepal_vat.export_rule' => null]);
    expect(issueInvoice(vatModeClient('US', 'USD'), [10_000])->fresh()->supply_class)->toBe('exempt');
});

test('a document with only non-Nepal tax is exempt for the register: its tax stays but is not Nepal VAT', function () {
    vatModeSwitchOn();
    $invoice = issueInvoice(vatModeClient('IN', 'USD'), [10_000])->fresh();

    expect($invoice)->supply_class->toBe('exempt')->fiscal_tax1->toBe(1_800)->fiscal_tax1_is_vat->toBeFalse()->fiscal_tax2_is_vat->toBeFalse()
        ->tax1_rule_id->toBe($this->gst->id);
});

test('a draft calculated before rule ids were stored is matched to a Nepal VAT rule by name and rate', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    vatModeSwitchOn();
    $invoice = issueInvoice(vatModeClient(), [10_000]);
    DB::table('invoices')->where('id', $invoice->id)->update(['tax1_rule_id' => null, 'tax2_rule_id' => null]);
    payInvoice($invoice->fresh(), 11_500);

    expect($invoice->fresh())->supply_class->toBe('taxable')->fiscal_tax1_is_vat->toBeTrue()->fiscal_tax2_is_vat->toBeFalse();
});

test('the split command classifies invoices numbered before the mode, caps its work and repeats safely', function () {
    $nepal = issueInvoice(vatModeClient(), [10_000]);
    $india = issueInvoice(vatModeClient('IN', 'USD'), [10_000]);
    $free = issueInvoice(vatModeClient('US', 'USD'), [10_000]);
    $deposit = Invoice::query()->whereKey(issueInvoice(vatModeClient(), [10_000])->id);
    $deposit->update(['kind' => 'add_funds']);
    DB::table('invoices')->update(['tax1_rule_id' => null, 'tax2_rule_id' => null]);

    $this->artisan('billing:fiscal-vat-split')->expectsOutputToContain('0 invoices classified')->assertSuccessful();

    vatModeSwitchOn(['nepal_vat.enabled' => false]);
    $this->artisan('billing:fiscal-vat-split --max=1')->expectsOutputToContain('1 invoices classified')->assertSuccessful();
    $this->artisan('billing:fiscal-vat-split')->expectsOutputToContain('1 invoices classified')->assertSuccessful();
    $this->artisan('billing:fiscal-vat-split')->expectsOutputToContain('0 invoices classified')->assertSuccessful();

    expect($nepal->fresh())->supply_class->toBe('taxable')->fiscal_tax1_is_vat->toBeTrue()->fiscal_tax2_is_vat->toBeFalse()
        ->and($india->fresh())->supply_class->toBe('exempt')->fiscal_tax1_is_vat->toBeFalse()
        ->and($free->fresh()->fiscal_tax1_is_vat)->toBeNull()
        ->and(DB::table('invoices')->where('id', $deposit->value('id'))->value('fiscal_tax1_is_vat'))->toBeNull();
});

test('the Billing settings save refuses to change a numbering setting while the mode is on, and accepts it when off', function () {
    $update = fn (string $format) => app(UpdateSettings::class)->handle('billing', ['billing' => ['number_format' => $format, 'number_padding' => 0]]);

    expect($update('INV-{NUMBER}'))->toBe(['billing.number_format'])
        ->and(app(BillingSettings::class)->string('number_format'))->toBe('INV-{NUMBER}');

    vatModeSwitchOn();

    expect(fn () => $update('X-{NUMBER}'))->toThrow(ValidationException::class)
        ->and($update('INV-{NUMBER}'))->toBe([])
        ->and(app(BillingSettings::class)->string('number_format'))->toBe('INV-{NUMBER}');
});

test('the Billing settings form says why a numbering field is fixed while the mode is on', function () {
    $reason = fn () => collect(app(SettingsCatalog::class)->fields('billing'))->firstWhere('key', 'billing.number_format')['locked_reason'];

    expect($reason())->toBeNull();

    vatModeSwitchOn();

    expect($reason())->toStartWith('Fixed while Nepal VAT mode is on');
});

test('a tax rule the mode relies on cannot be deleted', function () {
    vatModeSwitchOn();
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->delete(route('admin.billing.tax-rules.destroy', $this->vat))->assertRedirect();
    $this->delete(route('admin.billing.tax-rules.destroy', $this->export))->assertRedirect();
    $this->delete(route('admin.billing.tax-rules.destroy', $this->gst))->assertRedirect();

    expect(TaxRule::query()->whereKey([$this->vat->id, $this->export->id])->count())->toBe(2)->and(TaxRule::query()->whereKey($this->gst->id)->exists())->toBeFalse();
});

test('the settings page shows the rules, the next BS months and what blocks enabling, in Nepal terms', function () {
    actingAsStaff(['billing.tax.manage']);

    $this->get(route('admin.billing.vat-mode.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/billing/vat-mode/edit')
        ->where('mode.enabled', false)
        ->where('mode.vat_rules', [])
        ->where('months.0', ['value' => '2026-10-18', 'label' => 'Kartik 2083 BS (from 18 Oct 2026)'])
        ->where('months.1.value', '2026-11-17')
        ->has('rules', 4)
        ->where('blockers', []));

    actingAsStaff(['billing.invoices.view']);
    $this->get(route('admin.billing.vat-mode.edit'))->assertForbidden();
});

test('the page and the save are hidden (404) until the mode is made available', function () {
    config(['features.billing.nepal_vat_mode' => false]);
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->get(route('admin.billing.vat-mode.edit'))->assertNotFound();
    $this->put(route('admin.billing.vat-mode.update'), vatModePayload())->assertNotFound();
    expect(app(NepalVatMode::class)->enabled())->toBeFalse();
});

test('a tax rule the mode uses cannot have its rate changed, other rules can', function () {
    vatModeSwitchOn();
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();
    $body = fn (TaxRule $rule, string $rate) => ['name' => $rule->name, 'level' => $rule->level, 'country' => $rule->country, 'rate' => $rate, 'priority' => 0];

    $this->put(route('admin.billing.tax-rules.update', $this->export), $body($this->export, '13.000'))->assertRedirect();
    $this->put(route('admin.billing.tax-rules.update', $this->vat), $body($this->vat, '15.000'))->assertRedirect();
    $this->put(route('admin.billing.tax-rules.update', $this->vat), [...$body($this->vat, '13.000'), 'country' => 'IN'])->assertRedirect();
    $this->put(route('admin.billing.tax-rules.update', $this->vat), [...$body($this->vat, '13.000'), 'level' => 2])->assertRedirect();
    $this->put(route('admin.billing.tax-rules.update', $this->gst), $body($this->gst, '12.000'))->assertRedirect();

    expect((float) $this->export->fresh()->rate)->toBe(0.0)->and((float) $this->vat->fresh()->rate)->toBe(13.0)->and($this->vat->fresh())->country->toBe('NP')->level->toBe(1)
        ->and((float) $this->gst->fresh()->rate)->toBe(12.0);
});

test('the split classifies an export-rule invoice as export and keeps a renamed rule by its id', function () {
    $abroad = issueInvoice(vatModeClient('US', 'USD'), [10_000]);
    $home = issueInvoice(vatModeClient(), [10_000]);
    $this->vat->update(['name' => 'VAT 13%']);
    billingSettings(['nepal_vat.vat_rules' => [$this->vat->id], 'nepal_vat.export_rule' => $this->export->id]);

    $this->artisan('billing:fiscal-vat-split --dry-run')->expectsOutputToContain('would be classified')->assertSuccessful();
    expect($abroad->fresh()->fiscal_tax1_is_vat)->toBeNull();

    $this->artisan('billing:fiscal-vat-split')->assertSuccessful();

    expect($abroad->fresh())->supply_class->toBe('export')->fiscal_tax1_is_vat->toBeFalse()
        ->and($home->fresh())->supply_class->toBe('taxable')->fiscal_tax1_is_vat->toBeTrue();
});

test('turning the feature flag off after enabling hides the page but the mode stays in effect', function () {
    vatModeSwitchOn();
    config(['features.billing.nepal_vat_mode' => false]);

    $invoice = issueInvoice(vatModeClient(), [10_000])->fresh();

    expect(app(NepalVatMode::class)->enabled())->toBeTrue()->and($invoice)->supply_class->toBe('taxable')->fiscal_tax1_is_vat->toBeTrue()
        ->and(collect(app(SettingsCatalog::class)->fields('billing'))->firstWhere('key', 'billing.number_format')['locked_reason'])->not->toBeNull();
});

test('a Billing settings save does not create the mode row; the settings page and enabling do', function () {
    app(UpdateSettings::class)->handle('billing', ['billing' => ['number_padding' => 3]]);
    expect(DB::table('settings')->where('key', 'billing.nepal_vat.enabled')->exists())->toBeFalse();

    actingAsStaff(['billing.tax.manage']);
    $this->get(route('admin.billing.vat-mode.edit'))->assertOk();

    expect(DB::table('settings')->where('key', 'billing.nepal_vat.enabled')->exists())->toBeTrue()->and(app(NepalVatMode::class)->enabled())->toBeFalse();
});
