<?php

namespace App\Modules\Orders\Fraud;

use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Models\FraudCheck;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Pricing\PromotionEligibility;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the configured fraud provider for an order and records the result. Existing clients may be
 * skipped; provider errors and timeouts become "error", treated as review when failing open.
 */
class FraudScreening
{
    /** @var array<string, class-string<FraudChecker>> */
    public const DRIVERS = [
        'none' => NoneFraudChecker::class,
        'rules' => RulesFraudChecker::class,
    ];

    public function __construct(
        private Container $container,
        private Settings $settings,
        private PromotionEligibility $eligibility,
    ) {}

    public function checker(): FraudChecker
    {
        $driver = (string) $this->settings->get('orders.fraud.driver', 'rules');

        return $this->container->make(self::DRIVERS[$driver] ?? NoneFraudChecker::class);
    }

    /**
     * Screen the order, store a fraud_checks row and the decision on the order.
     */
    public function screen(Order $order, bool $skip = false): FraudDecision
    {
        $checker = $this->checker();
        $client = $order->client;
        $started = hrtime(true);

        if ($skip || ($this->settings->get('orders.fraud.skip_for_existing_clients', true) && $this->eligibility->hasActiveService($client))) {
            $result = new FraudResult(FraudDecision::Skipped, reasons: [$skip ? 'Skipped by staff.' : 'Existing client with an active service.']);
        } else {
            try {
                $result = $checker->check(new FraudContext(
                    orderId: $order->id,
                    clientId: $client->id,
                    email: $client->email,
                    ipAddress: $order->ip_address,
                    country: $client->country,
                    total: $order->total,
                    currency: $order->currency,
                ));
            } catch (Throwable $exception) {
                report($exception);
                $result = new FraudResult(FraudDecision::Error, reasons: ['Fraud provider error: '.class_basename($exception)]);
            }
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($result->decision !== FraudDecision::Error && $durationMs > (int) $this->settings->get('orders.fraud.timeout_ms', 5000)) {
            $result = new FraudResult(FraudDecision::Error, $result->score, ["Fraud check exceeded the {$durationMs} ms timeout."], $result->raw);
        }

        FraudCheck::query()->create([
            'order_id' => $order->id,
            'provider' => $checker->name(),
            'score' => $result->score,
            'decision' => $result->decision,
            'reasons' => $result->reasons,
            'raw' => $result->raw,
            'duration_ms' => $durationMs,
        ]);

        $order->forceFill([
            'fraud_provider' => $checker->name(),
            'fraud_score' => $result->score,
            'fraud_decision' => $result->decision,
            'fraud_payload' => ['reasons' => $result->reasons],
        ])->save();

        return $this->effectiveDecision($result->decision);
    }

    /**
     * How a decision is acted upon: errors count as review when failing open, otherwise as fail.
     */
    public function effectiveDecision(FraudDecision $decision): FraudDecision
    {
        if ($decision !== FraudDecision::Error) {
            return $decision;
        }

        return $this->settings->get('orders.fraud.fail_open', true) ? FraudDecision::Review : FraudDecision::Fail;
    }
}
