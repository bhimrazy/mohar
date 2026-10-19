<?php

namespace Mohar\Simulators\Providers\Stripe;

use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * The hosted payment page a Checkout Session's url opens, as the customer's browser sees it:
 *
 * - Pay creates the PaymentIntent, Charge and balance transaction (fee 2.9% + 30), completes the session, and
 *   redirects to the success URL;
 * - Decline leaves the session open with a failed PaymentIntent (`card_declined`), as a declined card does;
 * - Back abandons: it redirects to the cancel URL and changes nothing.
 */
final class HostedCheckout
{
    public function __construct(private readonly Scenario $scenario) {}

    public function page(string $id, string $notice = '', int $status = 200): Response
    {
        $session = $this->scenario->state()->get('sessions', $id);
        $checkout = $this->scenario->state()->get('checkout', $id);

        if (! $this->payable($session) || ! is_array($checkout)) {
            return $this->html(410, '<p>This checkout session has expired or is complete.</p>');
        }

        $amount = number_format($session['amount_total'] / 100, 2).' '.strtoupper($session['currency']);
        $buttons = '';

        foreach (['pay' => 'Pay '.$amount, 'decline' => 'Pay with a card that is declined', 'back' => 'Back'] as $choice => $label) {
            $buttons .= '<form method="post" action="/c/pay/'.rawurlencode($id).'/'.$choice.'"><button>'.htmlspecialchars($label).'</button></form>';
        }

        return $this->html($status, ($notice !== '' ? '<p role="alert">'.htmlspecialchars($notice).'</p>' : '')
            .'<h1>'.htmlspecialchars($checkout['description']).'</h1><p>'.htmlspecialchars($amount).'</p>'.$buttons);
    }

    public function choose(string $id, string $choice): Response
    {
        $state = $this->scenario->state();
        $session = $state->get('sessions', $id);
        $checkout = $state->get('checkout', $id);

        if (! $this->payable($session) || ! is_array($checkout)) {
            return $this->html(410, '<p>This checkout session has expired or is complete.</p>');
        }

        $stripe = new StripeApi($this->scenario);
        $intentId = is_string($session['payment_intent']) ? $session['payment_intent'] : $stripe->newId('pi_');
        $intent = [
            'id' => $intentId,
            'object' => 'payment_intent',
            'amount' => $session['amount_total'],
            'amount_received' => 0,
            'currency' => $session['currency'],
            'description' => $checkout['intent_data']['description'] ?? null,
            'last_payment_error' => null,
            'latest_charge' => null,
            'livemode' => false,
            'metadata' => (array) ($checkout['intent_data']['metadata'] ?? []),
            'payment_method' => $stripe->newId('pm_'),
            'status' => 'requires_payment_method',
        ];

        if ($choice === 'back') {
            return new Response(303, ['Location' => $checkout['cancel_url']]);
        }

        if ($choice === 'decline') {
            $intent['last_payment_error'] = ['code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.', 'type' => 'card_error'];
            $state->put('payment_intents', $intentId, $intent);
            $state->put('sessions', $id, [...$session, 'payment_intent' => $intentId]);
            $stripe->emit('payment_intent.payment_failed', $intent);

            return $this->page($id, 'Your card was declined. Try another card.', 402);
        }

        if ($choice !== 'pay') {
            return $this->html(404, '<p>No such choice.</p>');
        }

        $amount = (int) $session['amount_total'];
        $fee = (int) round($amount * 0.029) + 30;
        $balance = ['id' => $stripe->newId('txn_'), 'object' => 'balance_transaction', 'amount' => $amount, 'currency' => $session['currency'], 'fee' => $fee, 'net' => $amount - $fee];
        $charge = ['id' => $stripe->newId('ch_'), 'object' => 'charge', 'amount' => $amount, 'amount_refunded' => 0, 'balance_transaction' => $balance['id'], 'currency' => $session['currency'], 'paid' => true, 'payment_intent' => $intentId, 'refunded' => false, 'status' => 'succeeded'];
        $intent = [...$intent, 'amount_received' => $amount, 'last_payment_error' => null, 'latest_charge' => $charge['id'], 'status' => 'succeeded'];
        $session = [...$session, 'payment_intent' => $intentId, 'payment_status' => 'paid', 'status' => 'complete', 'url' => null];

        $state->transaction(function () use ($state, $balance, $charge, $intent, $session): void {
            $state->put('balance', $balance['id'], $balance);
            $state->put('charges', $charge['id'], $charge);
            $state->put('payment_intents', $intent['id'], $intent);
            $state->put('sessions', $session['id'], $session);
        });
        $stripe->emit('checkout.session.completed', $session);
        $stripe->emit('payment_intent.succeeded', $intent);

        return new Response(303, ['Location' => str_replace('{CHECKOUT_SESSION_ID}', $id, $checkout['success_url'])]);
    }

    private function payable(mixed $session): bool
    {
        return is_array($session) && $session['status'] === 'open' && $session['expires_at'] > time();
    }

    private function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Test checkout</title>'.$body);
    }
}
