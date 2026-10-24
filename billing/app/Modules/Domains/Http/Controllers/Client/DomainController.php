<?php

namespace App\Modules\Domains\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\IssueRenewalNow;
use App\Modules\Domains\Actions\ResubmitTransfer;
use App\Modules\Domains\Actions\RetrieveEppCode;
use App\Modules\Domains\Actions\SetAutoRenew;
use App\Modules\Domains\Actions\SetRegistrarLock;
use App\Modules\Domains\Actions\UpdateContacts;
use App\Modules\Domains\Actions\UpdateNameservers;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Http\Requests\ContactsRequest;
use App\Modules\Domains\Http\Requests\NameserversRequest;
use App\Modules\Domains\Http\Requests\RenewNowRequest;
use App\Modules\Domains\Http\Requests\ToggleRequest;
use App\Modules\Domains\Http\Requests\TransferCodeRequest;
use App\Modules\Domains\Http\Resources\DomainResource;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;
use App\Modules\Domains\Services\DomainPricingService;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client self-service. Every lookup is scoped to the current client account (no IDOR).
 */
class DomainController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function index(Request $request): Response
    {
        $this->context->authorize('domains');

        $domains = Domain::query()
            ->where('client_id', $this->context->id())
            ->with('tldModel:id,registered_by_client')
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw('case when expiry_date is null then 1 else 0 end')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('client/domains/index', [
            'domains' => DomainResource::collection($domains),
            'filters' => $request->only(['search', 'status']),
            'expiringSoon' => Domain::query()->where('client_id', $this->context->id())->expiringWithin(30)->count(),
        ]);
    }

    public function show(int $domain, RegistrarDriverRegistry $registrars, DomainPricingService $pricing): Response
    {
        $model = $this->domain($domain)->load(['contacts', 'tldModel:id,registered_by_client']);
        $client = $this->context->client();
        $contacts = [];

        foreach (DomainContact::ROLES as $role) {
            $contact = $model->contacts->firstWhere('role', $role);
            $contacts[$role] = $contact?->only(DomainContact::FIELDS) ?? array_fill_keys(DomainContact::FIELDS, null);
        }

        return Inertia::render('client/domains/show', [
            'domain' => new DomainResource($model),
            'contacts' => $contacts,
            'capabilities' => match (true) {
                $model->registrar_key === null => ['nameservers', 'contacts'],
                $registrars->has($model->registrar_key) => $registrars->capabilitiesOf($model->registrar_key),
                default => [],
            },
            'canManage' => $model->status->allowsClientActions(),
            'canResubmitTransfer' => $model->type === 'transfer' && $model->status === DomainStatus::Pending && $model->provision_invoice_id !== null,
            'renewalTerms' => $pricing->renewalTerms($model, $model->currency, $client->client_group_id),
            'canRenewNow' => $model->status->allowsClientActions() && ! $model->isRegisteredByClient() && (bool) app(Settings::class)->get('domains.enable_renewal_orders', true)
                && ! ($model->isFree() && $model->linked_service_status === 'active'),
        ]);
    }

    public function nameservers(NameserversRequest $request, int $domain, UpdateNameservers $update): RedirectResponse
    {
        $update->handle($this->manageable($domain), $request->nameservers());

        return $this->done(__('Nameservers saved. Changes can take up to 24 hours to spread across the internet.'), $domain);
    }

    public function autoRenew(ToggleRequest $request, int $domain, SetAutoRenew $autoRenew): RedirectResponse
    {
        $autoRenew->handle($this->manageable($domain), $request->boolean('enabled'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('enabled') ? __('Auto-renew is on.') : __('Auto-renew is off. Renew the domain before it expires to keep it.')]);

        // Toggled from the dashboard or the domain page: return there, but never to another host.
        $fallback = route('client.domains.show', $domain);
        $previous = url()->previous($fallback);

        return redirect()->to(parse_url($previous, PHP_URL_HOST) === $request->getHost() ? $previous : $fallback);
    }

    public function lock(ToggleRequest $request, int $domain, SetRegistrarLock $lock): RedirectResponse
    {
        $lock->handle($this->manageable($domain), $request->boolean('enabled'));

        return $this->done($request->boolean('enabled') ? __('Domain locked against transfers.') : __('Domain unlocked. It can now be transferred away.'), $domain);
    }

    public function contacts(ContactsRequest $request, int $domain, UpdateContacts $update): RedirectResponse
    {
        $update->handle($this->manageable($domain), $request->contacts());

        return $this->done(__('Contact details saved.'), $domain);
    }

    public function epp(Request $request, int $domain, RetrieveEppCode $retrieve): RedirectResponse
    {
        $this->context->authorize('domains');
        $result = $retrieve->handle($this->manageable($domain), 'client', $request->user());
        Inertia::flash('epp', ['code' => $result->code, 'emailed' => $result->emailed]);

        return $this->done($result->emailed ? __('Your registrar has emailed the EPP code to the registrant contact.') : __('Your EPP code is shown below.'), $domain);
    }

    public function resubmitTransfer(TransferCodeRequest $request, int $domain, ResubmitTransfer $resubmit): RedirectResponse
    {
        $resubmit->handle($this->domain($domain), $request->string('epp_code')->toString());

        return $this->done(__('Transfer resubmitted. We will let you know when it completes.'), $domain);
    }

    public function renew(RenewNowRequest $request, int $domain, IssueRenewalNow $renew): RedirectResponse
    {
        $invoice = $renew->handle($this->domain($domain), 'client', $request->filled('years') ? $request->integer('years') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Renewal invoice :number created. Pay it to renew your domain.', ['number' => $invoice->number])]);

        return Route::has('client.invoices.show')
            ? to_route('client.invoices.show', $invoice->invoiceId)
            : to_route('client.domains.show', $domain);
    }

    private function domain(int $id): Domain
    {
        $this->context->authorize('domains');

        return Domain::query()->where('client_id', $this->context->id())->with('tldModel:id,registered_by_client')->findOrFail($id);
    }

    private function manageable(int $id): Domain
    {
        $domain = $this->domain($id);

        if (! $domain->status->allowsClientActions()) {
            throw new DomainActionNotAllowed("This domain is {$domain->status->label()}; it can't be changed right now.");
        }

        return $domain;
    }

    private function done(string $message, int $domain): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('client.domains.show', $domain);
    }
}
