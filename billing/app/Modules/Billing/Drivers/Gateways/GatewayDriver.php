<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Drivers\Gateways\Data\CallbackEvent;
use App\Modules\Billing\Drivers\Gateways\Data\CardToken;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeRequest;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Payment gateway driver (§6). Capabilities: redirect, capture, tokenize, remote_input, refund, webhook,
 * pending_settlement. Drivers never receive or store raw card data except through their remote input.
 */
interface GatewayDriver
{
    public const CAPTURE = 'capture';

    public const TOKENIZE = 'tokenize';

    public const REMOTE_INPUT = 'remote_input';

    public const REFUND = 'refund';

    public const WEBHOOK = 'webhook';

    public const PENDING_SETTLEMENT = 'pending_settlement';

    public const REDIRECT = 'redirect';

    public function key(): string;

    public function name(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Settings staff can configure, e.g. [['key' => 'instructions', 'label' => 'Instructions', 'type' => 'text']].
     *
     * @return list<array{key: string, label: string, type: 'string'|'text'|'secret', help?: string}>
     */
    public function settingsFields(): array;

    /**
     * Offline payment instructions shown to the client, or null.
     */
    public function instructions(Gateway $gateway, Invoice $invoice): ?string;

    public function capture(Gateway $gateway, ChargeRequest $request): ChargeResult;

    public function refund(Gateway $gateway, Transaction $payment, Money $amount): ChargeResult;

    /**
     * Resolve a token produced by the gateway's remote input into display details (null if invalid).
     */
    public function tokenDetails(Gateway $gateway, string $token): ?CardToken;

    /**
     * Verify the signature and parse a webhook.
     *
     * @throws InvalidCallbackException
     */
    public function parseCallback(Gateway $gateway, Request $request): CallbackEvent;
}
