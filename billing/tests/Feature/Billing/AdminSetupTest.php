<?php

use App\Events\Billing\GatewayConfigurationChanged;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\ActivityLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/** Sensitive staff actions require a recent password confirmation (ConfirmPasswordForAction). */
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

test('gateways can be configured without exposing stored secrets', function () {
    actingAsStaff(['billing.gateways.manage']);

    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), [
        'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 1,
        'settings' => ['webhook_secret' => 'whsec_1', 'fee_percent' => '2.9'],
    ])->assertRedirect(route('admin.billing.gateways.index'));

    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), [
        'display_name' => 'Credit card', 'is_active' => true, 'show_on_order_form' => true,
        'settings' => ['webhook_secret' => '', 'fee_percent' => '3'],
    ]);

    $gateway = Gateway::query()->where('key', 'sandbox_card')->firstOrFail();
    expect($gateway->display_name)->toBe('Credit card')
        ->and($gateway->setting('webhook_secret'))->toBe('whsec_1')
        ->and($gateway->setting('fee_percent'))->toBe('3');

    $this->get(route('admin.billing.gateways.edit', 'sandbox_card'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing/gateways/edit')
            ->where('gateway.settings.webhook_secret', '')
            ->where('gateway.secrets_set.webhook_secret', true));

    $this->get(route('admin.billing.gateways.index'))->assertInertia(fn (Assert $page) => $page->component('admin/billing/gateways/index')->has('gateways', 3));
});

test('deactivating a gateway can move unpaid invoices to a replacement', function () {
    actingAsStaff(['billing.gateways.manage']);
    Gateway::factory()->create();
    $invoice = issueInvoice(billingClient(), gateway: 'bank_transfer');

    $this->put(route('admin.billing.gateways.update', 'bank_transfer'), [
        'display_name' => 'Bank transfer', 'is_active' => false, 'show_on_order_form' => true, 'replacement' => 'manual',
    ])->assertRedirect();

    expect($invoice->fresh()->payment_gateway)->toBe('manual');
});

test('gateway configuration requires its permission', function () {
    actingAsStaff(['billing.invoices.*']);

    $this->get(route('admin.billing.gateways.index'))->assertForbidden();
    $this->put(route('admin.billing.gateways.update', 'bank_transfer'), ['display_name' => 'X'])->assertForbidden();
});

test('tax rules are created, validated, updated and deleted', function () {
    actingAsStaff(['billing.tax.manage']);

    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'VAT', 'level' => 1, 'country' => 'de', 'rate' => '19'])->assertRedirect();
    $rule = TaxRule::query()->firstOrFail();
    expect($rule)->country->toBe('DE')->rate->toBe('19.000');

    $this->post(route('admin.billing.tax-rules.store'), ['name' => '', 'level' => 3, 'country' => 'DEU', 'rate' => '150'])
        ->assertSessionHasErrors(['name', 'level', 'country', 'rate']);

    $this->put(route('admin.billing.tax-rules.update', $rule), ['name' => 'MwSt', 'level' => 1, 'country' => 'DE', 'rate' => '7'])->assertRedirect();
    expect($rule->fresh()->rate)->toBe('7.000');

    $this->get(route('admin.billing.tax-rules.index'))->assertInertia(fn (Assert $page) => $page->component('admin/billing/tax-rules/index')->has('rules', 1));

    $this->delete(route('admin.billing.tax-rules.destroy', $rule))->assertRedirect();
    expect(TaxRule::query()->count())->toBe(0);
});

test('the gateway log lists redacted entries', function () {
    actingAsStaff(['billing.gateway_log.view']);
    GatewayLog::query()->create(['gateway' => 'sandbox_card', 'direction' => 'callback', 'result' => 'payment recorded', 'payload' => ['txn_id' => 'x']]);

    $this->get(route('admin.billing.gateway-log.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/billing/gateway-log/index')->has('entries.data', 1));
});

test('staff adjust a client credit balance', function () {
    actingAsStaff(['billing.credit.manage', 'billing.credit.adjust']);
    $client = billingClient();

    $this->post(route('admin.billing.credit.store'), ['client_id' => $client->id, 'direction' => 'add', 'amount' => '25.00', 'description' => 'Goodwill'])->assertRedirect();
    $this->post(route('admin.billing.credit.store'), ['client_id' => $client->id, 'direction' => 'remove', 'amount' => '30.00', 'description' => 'Too much'])->assertSessionHasErrors('billing');

    $this->get(route('admin.billing.credit.index', ['client' => $client->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/billing/credit/index')->where('client.balance', 2_500)->has('entries.data', 1));
});

test('quotes are created, sent and converted once', function () {
    actingAsStaff(['billing.quotes.*']);
    $client = billingClient();

    $this->post(route('admin.billing.quotes.store'), [
        'client_id' => $client->id, 'subject' => 'Migration',
        'items' => [['description' => 'Migration', 'quantity' => 2, 'unit_amount' => '100.00', 'discount_percent' => 10]],
    ])->assertRedirect();

    $quote = Quote::query()->firstOrFail();
    expect($quote->total)->toBe(18_000);

    $this->post(route('admin.billing.quotes.deliver', $quote))->assertRedirect();
    expect($quote->fresh())->stage->value->toBe('delivered')->number->toBe('Q-1');

    $this->post(route('admin.billing.quotes.convert', $quote))->assertRedirect();
    $this->post(route('admin.billing.quotes.convert', $quote))->assertSessionHasErrors('billing');

    $invoice = Invoice::query()->where('client_id', $client->id)->sole();
    expect($invoice->total)->toBe(18_000)
        ->and($invoice->items()->orderBy('sort_order')->get(['description', 'quantity', 'unit_amount', 'amount'])->toArray())->toBe([
            ['description' => 'Migration (10% discount)', 'quantity' => 2, 'unit_amount' => 9_000, 'amount' => 18_000],
        ]);
    $this->get(route('admin.billing.quotes.show', $quote))->assertInertia(fn (Assert $page) => $page->component('admin/billing/quotes/show')->where('quote.invoice_id', $quote->fresh()->invoice_id));
});

test('gateway, tax, refund and credit changes are audit-logged without secret values', function () {
    Event::fake([GatewayConfigurationChanged::class]);
    billingStaff();
    $client = billingClient();

    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), [
        'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'settings' => ['webhook_secret' => 'whsec_very_secret_value'],
    ]);
    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'VAT', 'level' => 1, 'rate' => '5']);
    $this->post(route('admin.billing.credit.store'), ['client_id' => $client->id, 'direction' => 'add', 'amount' => '5.00', 'description' => 'Goodwill']);
    $payment = payInvoice(issueInvoice($client, [1_000]), 1_000);
    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '5.00', 'mode' => 'record_only']);

    $log = ActivityLog::query()->pluck('description');
    expect($log)->toContain('Payment gateway sandbox_card changed', 'Tax rule created', 'Credit balance adjusted', 'Refund issued');

    $gatewayEntry = ActivityLog::query()->where('description', 'Payment gateway sandbox_card changed')->firstOrFail();
    expect(json_encode($gatewayEntry->properties))->not->toContain('whsec_very_secret_value')
        ->and($gatewayEntry->properties['changes']['settings.webhook_secret'])->toBe('rotated');
    Event::assertDispatched(GatewayConfigurationChanged::class, fn ($event) => $event->changes['settings.webhook_secret'] === 'rotated');
});

test('the tax rules page links to the billing settings section', function () {
    actingAsStaff(['billing.tax.manage']);

    $this->get(route('admin.billing.tax-rules.index'))->assertInertia(fn (Assert $page) => $page
        ->where('settings_url', Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'billing') : null));
});
