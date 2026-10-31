<?php

namespace App\Contracts\Billing;

use App\Contracts\Integrations\IntegrationDriver;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Data\Billing\CardToken;
use App\Data\Billing\ChargeRequest;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Data\Integrations\SettingField;
use Illuminate\Http\Request;

/**
 * Payment gateway driver. A driver ships in a gateway extension or a package and registers with
 * App\Support\Billing\GatewayDriverRegistry by key and class. It receives DTOs only and never touches Billing models.
 * Billing calls a method only when the driver declares the matching capability; extend
 * App\Support\Billing\AbstractGatewayDriver for defaults.
 */
interface GatewayDriver extends IntegrationDriver
{
    public const CAPTURE = 'capture';

    public const TOKENIZE = 'tokenize';

    public const REMOTE_INPUT = 'remote_input';

    public const REFUND = 'refund';

    public const PARTIAL_REFUND = 'partial_refund';

    public const WEBHOOK = 'webhook';

    public const PENDING_SETTLEMENT = 'pending_settlement';

    public const REDIRECT = 'redirect';

    public const LOOKUP = 'lookup';

    public const QR = 'qr';

    public const STORED_METHODS = 'stored_methods';

    /** Unique key matching DriverRegistry::KEY; also the gateways.key and the callback/return URL segment. */
    public function key(): string;

    public function name(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Currencies the provider settles in (e.g. ['NPR']), or null for any. Other invoice currencies need the
     * gateway's convert_to_currency.
     *
     * @return list<string>|null
     */
    public function currencies(): ?array;

    /**
     * Staff-configurable settings, as SettingField (ADR 0009). Secret, SecretText and Certificate values are
     * write-only in the admin UI; a Certificate is a .pfx/.p12 upload whose PEM private key is the setting and whose
     * metadata the driver reads through GatewayConfig::certificate(). The older array shape (`type`, `label`, `help`,
     * `options`, `required`, `default`) is still read through SettingField::fromArray(): deprecated since 0.2.0, removed in 1.0.0.
     *
     * @return list<SettingField|array{key: string, label: string, type?: string, help?: string, options?: array<string, string>, required?: bool, default?: string|bool}>
     */
    public function settingsFields(): array;

    /**
     * Offline payment instructions for a document reference (invoice number), or null.
     */
    public function instructions(GatewayConfig $gateway, string $reference): ?string;

    /** Requires CAPTURE. */
    public function capture(GatewayConfig $gateway, ChargeRequest $request): ChargeResult;

    /** Requires REFUND (and PARTIAL_REFUND for partial amounts). */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult;

    /** Requires TOKENIZE: resolve a token from the gateway's remote input into display details (null if invalid). */
    public function tokenDetails(GatewayConfig $gateway, string $token): ?CardToken;

    /** Requires WEBHOOK: verify the signature and parse a pushed callback. */
    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection;
}
