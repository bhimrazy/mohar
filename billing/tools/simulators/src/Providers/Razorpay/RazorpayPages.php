<?php

namespace Mohar\Simulators\Providers\Razorpay;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * The pages a payer sees: the checkout an order opens (`/checkout`, standing in for Razorpay's script-driven modal)
 * and a payment link's page (`/l/{id}`). Each has Pay, Fail and, for the checkout, Cancel.
 *
 * The checkout hands the result to the merchant's `callback_url` with a browser POST (a self-submitting form), the
 * link redirects with a GET query, both carrying the signature the merchant must verify.
 */
final class RazorpayPages
{
    private readonly RazorpayApi $api;

    public function __construct(private readonly Scenario $scenario)
    {
        $this->api = new RazorpayApi($scenario);
    }

    /** GET /checkout?order_id=&callback_url=&cancel_url= */
    public function checkout(Request $request): Response
    {
        $order = $this->api->order((string) ($request->query['order_id'] ?? ''));
        $callback = (string) ($request->query['callback_url'] ?? '');

        if ($order === null || $order['status'] === 'paid' || ! self::isUrl($callback)) {
            return self::html(410, '<p>This checkout cannot be opened: the order is unknown or already paid, or there is no callback URL.</p>');
        }

        $hidden = self::hidden(['callback_url' => $callback, 'cancel_url' => (string) ($request->query['cancel_url'] ?? '')]);
        $buttons = '';

        foreach (['pay' => 'Pay (success@razorpay)', 'fail' => 'Pay (failure@razorpay)', 'cancel' => 'Cancel'] as $choice => $label) {
            $buttons .= '<form method="post" action="/checkout/'.rawurlencode($order['id']).'/'.$choice.'">'.$hidden.'<button>'.$label.'</button></form>';
        }

        return self::html(200, '<h1>Test checkout</h1><p>'.self::rupees($order['amount_due']).' for order '.htmlspecialchars($order['id']).'</p>'.$buttons);
    }

    /** POST /checkout/{order}/{choice} */
    public function checkoutChoice(Request $request, string $orderId, string $choice): Response
    {
        $input = $request->input();
        $callback = (string) ($input['callback_url'] ?? '');
        $cancel = (string) ($input['cancel_url'] ?? '');

        if (! in_array($choice, ['pay', 'fail', 'cancel'], true) || ! self::isUrl($callback)) {
            return self::html(400, '<p>Unknown choice or missing callback URL.</p>');
        }

        if ($choice === 'cancel') {
            return self::isUrl($cancel) ? new Response(303, ['Location' => $cancel]) : self::html(200, '<p>Payment cancelled.</p>');
        }

        $payment = $this->api->attempt($orderId, ['outcome' => $choice === 'fail' ? 'failed' : 'success']);

        if ($payment === null) {
            return self::html(410, '<p>This order is unknown or already paid.</p>');
        }

        return self::html(200, self::autoPost($callback, RazorpayApi::checkoutFields($payment)));
    }

    /** GET /l/{id} */
    public function link(string $id, ?string $notice = null): Response
    {
        $link = $this->api->link($id);

        if ($link === null || $link['status'] !== 'created') {
            return self::html(410, '<p>This payment link is '.htmlspecialchars($link['status'] ?? 'unknown').'.</p>');
        }

        $buttons = '';

        foreach (['pay' => 'Pay (success@razorpay)', 'fail' => 'Pay (failure@razorpay)'] as $choice => $label) {
            $buttons .= '<form method="post" action="/l/'.rawurlencode($id).'/'.$choice.'"><button>'.$label.'</button></form>';
        }

        return self::html(200, '<h1>'.htmlspecialchars((string) ($link['description'] ?? 'Payment')).'</h1><p>'.self::rupees($link['amount']).'</p>'.($notice === null ? '' : '<p role="alert">'.htmlspecialchars($notice).'</p>').$buttons);
    }

    /** POST /l/{id}/{choice} */
    public function linkChoice(string $id, string $choice): Response
    {
        $link = $this->api->link($id);

        if ($link === null || $link['status'] !== 'created' || ! in_array($choice, ['pay', 'fail'], true)) {
            return self::html(410, '<p>This payment link cannot be paid.</p>');
        }

        $payment = $this->api->attempt($link['order_id'], ['outcome' => $choice === 'fail' ? 'failed' : 'success']);

        if ($payment === null) {
            return self::html(410, '<p>This payment link cannot be paid.</p>');
        }

        if ($payment['status'] === 'failed') {
            return $this->link($id, 'The payment failed. You can try again.');
        }

        $link = (array) $this->api->link($id);
        $redirect = $this->linkRedirect($link, $payment);

        return $redirect === null ? self::html(200, '<p>Payment received.</p>') : new Response(303, ['Location' => $redirect]);
    }

    /**
     * Where a paid link sends the browser: its callback_url with the signed query, or null without one.
     *
     * @param  array<string, mixed>  $link
     * @param  array<string, mixed>  $payment
     */
    public function linkRedirect(array $link, array $payment): ?string
    {
        if (! is_string($link['callback_url']) || $link['callback_url'] === '') {
            return null;
        }

        $query = http_build_query(RazorpayApi::linkFields($link, $payment), '', '&', PHP_QUERY_RFC3986);

        return $link['callback_url'].(str_contains($link['callback_url'], '?') ? '&' : '?').$query;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function autoPost(string $url, array $fields): string
    {
        return '<form id="return" method="post" action="'.htmlspecialchars($url).'">'.self::hidden($fields).'<noscript><button>Continue</button></noscript></form>'
            .'<script>document.getElementById("return").submit()</script>';
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function hidden(array $fields): string
    {
        $html = '';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($value).'">';
        }

        return $html;
    }

    private static function isUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/]+#', $url) === 1;
    }

    private static function rupees(int $paise): string
    {
        return 'Rs '.number_format(intdiv($paise, 100)).'.'.sprintf('%02d', $paise % 100);
    }

    private static function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Test payment</title>'.$body);
    }
}
