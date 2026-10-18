<?php

namespace App\Modules\Orders\Fraud;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Models\FraudCheck;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Pricing\PromotionEligibility;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the configured fraud provider and records the result. Existing clients may be skipped; provider
 * errors and timeouts become "error", treated as review when failing open.
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
     * Ask the provider about a checkout. Runs outside any DB transaction (a slow provider never holds
     * locks); the provider gets the configured timeout and an overrun counts as an error.
     */
    public function assess(FraudContext $context, bool $skip = false, ?Client $client = null): FraudAssessment
    {
        $checker = $this->checker();
        $started = hrtime(true);
        $timeoutMs = (int) $this->settings->get('orders.fraud.timeout_ms', 5000);

        if ($skip || ($client !== null && $this->settings->get('orders.fraud.skip_for_existing_clients', true) && $this->eligibility->hasActiveService($client))) {
            $result = new FraudResult(FraudDecision::Skipped, reasons: [$skip ? 'Skipped by staff.' : 'Existing client with an active service.']);
        } else {
            try {
                $result = $checker->check(new FraudContext(...[...get_object_vars($context), 'timeoutMs' => $timeoutMs]));
            } catch (Throwable $exception) {
                report($exception);
                $result = new FraudResult(FraudDecision::Error, reasons: ['Fraud provider error: '.class_basename($exception)]);
            }
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($result->decision !== FraudDecision::Error && $durationMs > $timeoutMs) {
            $result = new FraudResult(FraudDecision::Error, $result->score, ["Fraud check exceeded the {$timeoutMs} ms timeout ({$durationMs} ms)."], $result->raw);
        }

        return new FraudAssessment($checker->name(), $result, $durationMs);
    }

    /**
     * Store the assessment on the order (fraud_checks row + order columns) and return how it is acted upon.
     */
    public function record(Order $order, FraudAssessment $assessment): FraudDecision
    {
        $result = $assessment->result;

        FraudCheck::query()->create([
            'order_id' => $order->id,
            'provider' => $assessment->provider,
            'score' => $result->score,
            'decision' => $result->decision,
            'reasons' => $result->reasons,
            'raw' => $result->raw,
            'duration_ms' => $assessment->durationMs,
        ]);

        $order->forceFill([
            'fraud_provider' => $assessment->provider,
            'fraud_score' => $result->score,
            'fraud_decision' => $result->decision,
            'fraud_payload' => ['reasons' => $result->reasons],
        ])->save();

        return $this->effectiveDecision($result->decision);
    }

    /**
     * Assess and record a stored order (staff re-run).
     */
    public function screen(Order $order, bool $skip = false): FraudDecision
    {
        $client = $order->client;

        return $this->record($order, $this->assess(new FraudContext(
            orderId: $order->id,
            clientId: $client->id,
            email: $client->email,
            ipAddress: $order->ip_address,
            country: $client->country,
            total: $order->total,
            currency: $order->currency,
        ), $skip, $client));
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
