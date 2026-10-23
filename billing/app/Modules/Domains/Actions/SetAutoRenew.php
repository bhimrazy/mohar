<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainAutoRenewChanged;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RenewalInvoices;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Toggle auto-renew. Turning it off cancels the unpaid renewal invoice of the cycle (spec §4.3);
 * renewal invoices issued by this module are single-domain, so cancelling never touches other items.
 */
class SetAutoRenew
{
    public function __construct(private RenewalInvoices $invoices, private ActivityLogger $activity) {}

    public function handle(Domain $domain, bool $enabled): Domain
    {
        if ($enabled && ! $domain->auto_renew && $domain->isRegisteredByClient()) {
            throw ValidationException::withMessages(['domain' => __("Auto-renew can't be turned on for a name registered at the registry by the client; it is renewed there.")]);
        }

        if ($domain->auto_renew === $enabled) {
            return $domain;
        }

        DB::transaction(function () use ($domain, $enabled): void {
            $domain->forceFill(['auto_renew' => $enabled])->save();
            $cancelled = $enabled ? 0 : $this->invoices->cancelOpen($domain);

            $this->activity->log($enabled ? 'Auto-renew enabled' : 'Auto-renew disabled', $domain, $domain->client_id, array_filter(['cancelled_invoices' => $cancelled]));
            DomainEvents::dispatch(new DomainAutoRenewChanged($domain->id, $domain->client_id, $enabled));
        });

        return $domain;
    }
}
