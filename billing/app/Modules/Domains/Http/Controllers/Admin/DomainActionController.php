<?php

namespace App\Modules\Domains\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\IssueRenewalNow;
use App\Modules\Domains\Actions\MoveDomainToClient;
use App\Modules\Domains\Actions\RetrieveEppCode;
use App\Modules\Domains\Actions\SetRegistrarLock;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Actions\UpdateContacts;
use App\Modules\Domains\Actions\UpdateNameservers;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Http\Requests\ContactsRequest;
use App\Modules\Domains\Http\Requests\ForceStatusRequest;
use App\Modules\Domains\Http\Requests\MoveDomainRequest;
use App\Modules\Domains\Http\Requests\NameserversRequest;
use App\Modules\Domains\Http\Requests\RenewNowRequest;
use App\Modules\Domains\Http\Requests\ToggleRequest;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Staff actions on a domain: forced status, registrar management and commands.
 */
class DomainActionController extends Controller
{
    public function status(ForceStatusRequest $request, Domain $domain, DomainStateMachine $states): RedirectResponse
    {
        $status = DomainStatus::from($request->string('status')->toString());
        $meta = $domain->registrar_meta ?? [];
        unset($meta['submission'], $meta['renew_sent_for']);

        // Staff have verified the registrar state: forcing a status also closes any unresolved submission.
        $states->transition($domain, $status, $request->string('reason')->toString(), [
            'needs_review' => false,
            'registrar_meta' => $meta ?: null,
        ], force: true);

        return $this->done(__('Status set to :status.', ['status' => $status->label()]), $domain);
    }

    public function nameservers(NameserversRequest $request, Domain $domain, UpdateNameservers $update): RedirectResponse
    {
        $update->handle($domain, $request->nameservers());

        return $this->done(__('Nameservers updated.'), $domain);
    }

    public function lock(ToggleRequest $request, Domain $domain, SetRegistrarLock $lock): RedirectResponse
    {
        $lock->handle($domain, $request->boolean('enabled'));

        return $this->done($request->boolean('enabled') ? __('Domain locked.') : __('Domain unlocked.'), $domain);
    }

    public function contacts(ContactsRequest $request, Domain $domain, UpdateContacts $update): RedirectResponse
    {
        $update->handle($domain, $request->contacts());

        return $this->done(__('Contacts updated.'), $domain);
    }

    public function epp(Request $request, Domain $domain, RetrieveEppCode $retrieve): RedirectResponse
    {
        $this->authorize('domains.domains.commands');
        $result = $retrieve->handle($domain, 'staff', $request->user());
        Inertia::flash('epp', ['code' => $result->code, 'emailed' => $result->emailed]);

        return $this->done($result->emailed ? __('The registrar emailed the EPP code to the registrant.') : __('EPP code retrieved.'), $domain);
    }

    public function move(MoveDomainRequest $request, Domain $domain, MoveDomainToClient $move): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));
        $move->handle($domain, $client);

        return $this->done(__('Domain moved to :client.', ['client' => $client->display_name]), $domain);
    }

    public function command(Request $request, Domain $domain, string $command, SyncDomain $sync): RedirectResponse
    {
        $this->authorize('domains.domains.commands');
        $staffId = $request->user()?->id;

        if (in_array($command, ['register', 'transfer'], true)) {
            if ($domain->status !== DomainStatus::Pending || $domain->type !== $command) {
                throw new DomainActionNotAllowed("Only a pending {$command} can be submitted.");
            }

            if (SubmitToRegistrar::hasOpenClaim($domain) || $domain->needs_review) {
                throw new DomainActionNotAllowed('A submission is in progress or awaiting verification. Check the registrar, then set the status.');
            }

            dispatch($command === 'transfer' ? new TransferDomain($domain->id, $staffId) : new RegisterDomain($domain->id, $staffId));

            return $this->done(__('The :command was queued.', ['command' => $command === 'transfer' ? 'transfer' : 'registration']), $domain);
        }

        if ($command === 'renew') {
            if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption, DomainStatus::Expired], true)) {
                throw new DomainActionNotAllowed("A {$domain->status->label()} domain cannot be renewed.");
            }

            if (isset($domain->registrar_meta['renew_sent_for']) || $domain->needs_review) {
                throw new DomainActionNotAllowed('A renewal is in progress or awaiting verification. Check the registrar first.');
            }

            RenewDomain::dispatch($domain->id, $domain->period_years, $domain->expiry_date?->toDateString(), null, $staffId)->afterCommit();

            return $this->done(__('Renewal for :years year(s) queued.', ['years' => $domain->period_years]), $domain);
        }

        $result = $sync->handle($domain, staffId: $staffId);

        if ($result['error'] !== null) {
            return back()->withErrors(['registrar' => $result['error']]);
        }

        return $this->done(trans_choice('{0} In sync with the registrar.|{1} Sync applied 1 change.|[2,*] Sync applied :count changes.', count($result['changes'])), $domain);
    }

    public function renewalInvoice(RenewNowRequest $request, Domain $domain, IssueRenewalNow $renew): RedirectResponse
    {
        $invoice = $renew->handle($domain, 'staff');

        return $this->done(__('Renewal invoice :number created.', ['number' => $invoice->number]), $domain);
    }

    private function done(string $message, Domain $domain): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.domains.show', $domain);
    }
}
