<?php

namespace App\Modules\Domains\Http\Resources;

use App\Modules\Domains\Models\Tld;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tld
 */
class TldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->only([
                'id', 'extension', 'display_unicode', 'registrar_key', 'auto_register', 'epp_required', 'min_years', 'max_years',
                'allow_register', 'allow_transfer', 'grace_days', 'grace_fee', 'redemption_days', 'redemption_fee',
                'renew_window_days', 'addon_dns', 'addon_id_protection', 'addon_email_forwarding', 'is_spotlight', 'badge',
                'sort_order', 'is_active',
            ]),
            'domains_count' => $this->whenCounted('domains'),
        ];
    }
}
