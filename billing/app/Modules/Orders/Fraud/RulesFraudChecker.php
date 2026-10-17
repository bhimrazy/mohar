<?php

namespace App\Modules\Orders\Fraud;

use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Str;

/**
 * Built-in rules (orders.md §7): too many pending orders for the client, too many orders from one IP
 * in 24 hours, banned email domains. Any triggered rule fails the order.
 */
class RulesFraudChecker implements FraudChecker
{
    private const RULES = 3;

    public function __construct(private Settings $settings) {}

    public function name(): string
    {
        return 'rules';
    }

    public function check(FraudContext $context): FraudResult
    {
        $reasons = [];
        $maxPending = (int) $this->settings->get('orders.fraud.max_pending_orders', 3);
        $maxPerIp = (int) $this->settings->get('orders.fraud.max_orders_per_ip', 5);

        $pending = Order::query()->where('client_id', $context->clientId)->where('status', OrderStatus::Pending)->count();

        if ($maxPending > 0 && $pending > $maxPending) {
            $reasons[] = "Client has {$pending} pending orders (limit {$maxPending}).";
        }

        if ($context->ipAddress !== null && $maxPerIp > 0) {
            $fromIp = Order::query()->where('ip_address', $context->ipAddress)->where('created_at', '>=', now()->subDay())->count();

            if ($fromIp > $maxPerIp) {
                $reasons[] = "{$fromIp} orders from this IP address in 24 hours (limit {$maxPerIp}).";
            }
        }

        $domain = Str::lower(Str::after($context->email, '@'));

        if (in_array($domain, $this->bannedDomains(), true)) {
            $reasons[] = "Email domain {$domain} is banned.";
        }

        return new FraudResult(
            decision: $reasons === [] ? FraudDecision::Pass : FraudDecision::Fail,
            score: round(count($reasons) / self::RULES * 100, 2),
            reasons: $reasons,
            raw: ['pending_orders' => $pending, 'email_domain' => $domain],
        );
    }

    /**
     * @return list<string>
     */
    private function bannedDomains(): array
    {
        $value = $this->settings->get('orders.fraud.banned_email_domains', '');
        $domains = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn (mixed $domain): string => Str::lower(trim((string) $domain)), $domains)));
    }
}
