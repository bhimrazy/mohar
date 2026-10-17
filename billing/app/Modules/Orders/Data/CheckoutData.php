<?php

namespace App\Modules\Orders\Data;

use App\Modules\Core\Models\Client;

/**
 * Everything PlaceOrder needs. Either `client` (signed-in shopper or staff-picked client) or
 * `registration` (inline sign-up, created in the order transaction) is set.
 */
final readonly class CheckoutData
{
    /**
     * @param  list<CartItem>  $items
     * @param  array{first_name: string, last_name: string, email: string, password: string, company_name?: ?string, country?: ?string}|null  $registration
     * @param  int|null  $expectedNetTotal  the pre-tax total the customer confirmed; checkout stops when prices changed since
     * @param  array<string, int>  $priceOverrides  cart item key => recurring amount (staff orders only)
     */
    public function __construct(
        public array $items,
        public string $currency,
        public string $paymentGateway,
        public ?string $promotionCode = null,
        public ?Client $client = null,
        public ?array $registration = null,
        public ?int $userId = null,
        public ?int $staffId = null,
        public ?string $notes = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $idempotencyKey = null,
        public ?string $referralCode = null,
        public bool $applyCredit = false,
        public bool $skipFraud = false,
        public bool $notifyInvoice = true,
        public ?int $expectedNetTotal = null,
        public array $priceOverrides = [],
    ) {}
}
