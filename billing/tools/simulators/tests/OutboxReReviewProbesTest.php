<?php

/*
 * PR #113 re-review probes (head 93bde8bb): a forged delivery variant (`stale`, `wrong_secret`, `unsigned`) is a test
 * of the app, not one of the provider's own attempts. Stripe never sees a forged webhook, so it must neither use up nor
 * delay nor complete the genuine delivery. All three fail at 93bde8bb.
 */

use Mohar\Simulators\Kernel\CallbackDispatcher;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->scenario = Scenario::create(EchoSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

test('forged variants the app refuses do not delay the genuine delivery', function () {
    $outbox = $this->scenario->outbox();
    $id = $outbox->enqueue('https://app.example.test/hook', '{"n":1}');
    $delivery = $outbox->pending()[0];

    foreach (['wrong_secret', 'stale', 'unsigned'] as $variant) {
        $outbox->deliver($delivery, fn (): int => 403, $variant);
    }

    // At 93bde8bb the third refusal pushed deliver_after 2 s ahead, so the genuine delivery is not due, and a loop over
    // pending() that expects 200 for each passes on an empty list.
    expect(array_column($outbox->pending(), 'id'))->toBe([$id]);
});

test('forged variants never exhaust the genuine delivery', function () {
    $outbox = $this->scenario->outbox();
    $outbox->retryPolicy([0], CallbackDispatcher::MAX_ATTEMPTS);
    $id = $outbox->enqueue('https://app.example.test/hook', '{"n":1}');
    $delivery = $outbox->pending()[0];

    foreach (range(1, CallbackDispatcher::MAX_ATTEMPTS) as $ignored) {
        $outbox->deliver($delivery, fn (): int => 403, 'wrong_secret');
    }

    expect($outbox->exhausted())->toBe([])
        ->and(array_column($outbox->drain(fn (): int => 200, maxSeconds: 5), 0))->toBe([$id]);
});

test('a forged variant the app wrongly accepts does not complete the genuine delivery', function () {
    $outbox = $this->scenario->outbox();
    $id = $outbox->enqueue('https://app.example.test/hook', '{"n":1}');

    $outbox->deliver($outbox->pending()[0], fn (): int => 200, 'wrong_secret');

    expect(array_column($outbox->pending(), 'id'))->toBe([$id]);
});
