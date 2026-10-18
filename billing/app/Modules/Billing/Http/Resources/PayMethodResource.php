<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\PayMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Display data of a stored pay method. The token is never exposed.
 *
 * @mixin PayMethod
 */
class PayMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'kind' => $this->kind,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'label' => $this->label(),
            'expiry' => $this->expiry_month !== null && $this->expiry_year !== null ? sprintf('%02d/%d', $this->expiry_month, $this->expiry_year) : null,
            'is_default' => $this->is_default,
            'expired' => $this->isExpired(),
            'disabled' => $this->disabled_at !== null,
        ];
    }
}
