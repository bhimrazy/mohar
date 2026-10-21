<?php

namespace App\Modules\Domains\Actions;

use App\Data\Domains\DomainDraft;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainPricingService;
use App\Modules\Domains\Support\DomainName;
use App\Modules\Domains\Support\Nameservers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Create a pending registration/transfer from an order item (no registrar call; spec §4.2:
 * submitted to the registrar only after payment).
 */
class CreatePendingDomain
{
    public function __construct(
        private DomainPricingService $pricing,
        private Settings $settings,
        private ActivityLogger $activity,
        private BuildDomainContacts $contacts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(DomainDraft $draft): Domain
    {
        try {
            $name = DomainName::toAscii($draft->domain, (bool) $this->settings->get('domains.allow_idn', true));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }

        $parts = $this->pricing->split($name);
        $tld = $parts === null ? null : $this->pricing->tld($parts[1]);

        if ($parts === null || $tld === null) {
            throw ValidationException::withMessages(['domain' => 'We do not offer this domain extension.']);
        }

        // The client registers these at the registry: never a registration or transfer here, whoever asks.
        if ($tld->registered_by_client) {
            throw ValidationException::withMessages(['domain' => __(':tld domains are not sold here. If you already own one, choose "Use a domain I own".', ['tld' => $tld->extension])]);
        }

        if (($draft->action === 'register' && ! $tld->allow_register) || ($draft->action === 'transfer' && ! $tld->allow_transfer)) {
            throw ValidationException::withMessages(['domain' => "{$tld->extension} domains cannot be {$draft->action}ed here."]);
        }

        if ($draft->action === 'transfer' && $tld->epp_required && trim((string) $draft->eppCode) === '') {
            throw ValidationException::withMessages(['epp_code' => 'Enter the EPP (transfer) code from your current registrar.']);
        }

        if ($draft->years < $tld->min_years || $draft->years > $tld->max_years) {
            throw ValidationException::withMessages(['years' => "Choose between {$tld->min_years} and {$tld->max_years} years."]);
        }

        $nameservers = Nameservers::normalise($draft->nameservers);

        if ($nameservers !== [] && ! Nameservers::isValidSet($nameservers)) {
            throw ValidationException::withMessages(['nameservers' => 'Enter 2 to 5 valid nameserver hostnames.']);
        }

        $nameservers = $nameservers ?: Nameservers::normalise(SettingList::of($this->settings->get('domains.default_nameservers', [])));

        return DB::transaction(function () use ($draft, $name, $parts, $tld, $nameservers): Domain {
            if (Domain::query()->where('active_name', $name)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['domain' => "{$name} is already registered with us."]);
            }

            $client = Client::query()->findOrFail($draft->clientId);
            $today = CarbonImmutable::today();

            $domain = Domain::query()->create([
                'client_id' => $client->id,
                'order_id' => $draft->orderId,
                'tld_id' => $tld->id,
                'name' => $name,
                'name_unicode' => DomainName::toUnicode($name),
                'sld' => $parts[0],
                'tld' => $parts[1],
                'type' => $draft->action,
                'registrar_key' => $tld->registrar_key,
                'status' => DomainStatus::Pending,
                'next_due_date' => $today,
                'next_invoice_date' => $today,
                'period_years' => $draft->years,
                'first_payment_amount' => $draft->firstPaymentAmount,
                'recurring_amount' => $draft->recurringAmount,
                'currency' => strtoupper($draft->currency),
                'auto_renew' => (bool) $this->settings->get('domains.default_auto_renew', true),
                'id_protection' => $draft->idProtection,
                'nameservers' => $nameservers,
                'epp_code' => $draft->action === 'transfer' ? trim((string) $draft->eppCode) : null,
            ]);

            $this->contacts->forNewDomain($domain, $client);
            $this->activity->log("Domain {$draft->action} created (pending payment)", $domain, $client->id, ['order_id' => $draft->orderId]);

            return $domain;
        });
    }
}
