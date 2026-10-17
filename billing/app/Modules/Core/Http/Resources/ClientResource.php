<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_1' => $this->address_1,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'tax_id' => $this->tax_id,
            'tax_exempt' => $this->tax_exempt,
            'currency' => $this->currency,
            'language' => $this->language,
            'status' => $this->status,
            'notes' => $this->when($request->user()?->is_staff, $this->notes),
            'client_group_id' => $this->client_group_id,
            'group' => $this->whenLoaded('group', fn () => $this->group?->only(['id', 'name', 'colour'])),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'merged_into_client_id' => $this->merged_into_client_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
