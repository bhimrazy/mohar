<?php

use App\Models\User;
use App\Modules\Notifications\Models\EmailMessage;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

uses(TestCase::class, SimulatorHarness::class);

/**
 * Review board probe for PR #115 (ADR 0016 S4) at cf492f1: the scenario asserts no email at all and does not check
 * that the address was verified, though both are outcomes the customer sees. This passes at that head (the emails are
 * sent); it shows the assertions to adopt into HostingLifecycleTest. It reuses that file's step functions, so run it
 * with the directory (`simulate:e2e` does).
 */
beforeEach(function () {
    $this->startSimulators();
});

afterEach(function () {
    $this->stopSimulators();
});

test('the customer is verified and is sent the order, invoice, payment and hosting welcome emails', function () {
    $product = installAndConfigure();
    $invoice = registerAndOrder($product);
    expect(User::query()->where('email', 'anna@example-shop.test')->sole()->hasVerifiedEmail())->toBeTrue();

    payWithStripe($invoice);

    expect(EmailMessage::query()->where('recipient', 'anna@example-shop.test')->pluck('template_key')->all())
        ->toContain('auth.email_verification', 'client.welcome', 'order.confirmation', 'invoice.created', 'invoice.payment_confirmation', 'service.welcome.shared_hosting');
});
