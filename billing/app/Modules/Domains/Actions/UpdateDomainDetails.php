<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainPricingService;
use Illuminate\Support\Facades\DB;

/**
 * Staff edit of dates, amounts, period, registrar and add-on flags (spec §7). "Recalculate recurring
 * amount" re-prices the renewal at the current renew price for the period.
 */
class UpdateDomainDetails
{
    public function __construct(
        private DomainPricingService $pricing,
        private SetAutoRenew $autoRenew,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(Domain $domain, array $data, bool $recalculateRecurring = false): Domain
    {
        return DB::transaction(function () use ($domain, $data, $recalculateRecurring): Domain {
            if (array_key_exists('auto_renew', $data)) {
                $this->autoRenew->handle($domain, (bool) $data['auto_renew']);
                unset($data['auto_renew']);
            }

            $domain->fill($data);

            if ($domain->isDirty('expiry_date') && $domain->expiry_date !== null) {
                $domain->billing_anchor_day = $domain->expiry_date->day;
            }

            if ($recalculateRecurring) {
                $price = $this->pricing->price($domain->tld, 'renew', $domain->period_years, $domain->currency, $domain->client->client_group_id);

                if ($price !== null) {
                    $domain->recurring_amount = $price;
                }
            }

            $changed = array_keys($domain->getDirty());
            $domain->save();

            if ($changed !== []) {
                $this->activity->log('Domain details updated', $domain, $domain->client_id, ['changed' => $changed]);
            }

            return $domain;
        });
    }
}
