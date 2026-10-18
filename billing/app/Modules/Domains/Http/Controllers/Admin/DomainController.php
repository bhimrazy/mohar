<?php

namespace App\Modules\Domains\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Actions\UpdateDomainDetails;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Http\Requests\UpdateDomainRequest;
use App\Modules\Domains\Http\Resources\DomainResource;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RenewalInvoices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(Request $request, RegistrarManager $registrars): Response
    {
        $this->authorize('domains.domains.view');

        $domains = Domain::query()
            ->with('client:id,first_name,last_name,company_name')
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('registrar'), fn (Builder $query) => $request->string('registrar')->toString() === 'manual'
                ? $query->whereNull('registrar_key')
                : $query->where('registrar_key', $request->string('registrar')->toString()))
            ->when($request->filled('tld'), fn (Builder $query) => $query->where('tld', Tld::normaliseExtension($request->string('tld')->toString())))
            ->when($request->integer('expiring') > 0, fn (Builder $query) => $query->expiringWithin(min(3650, $request->integer('expiring'))))
            ->when($request->boolean('attention'), fn (Builder $query) => $query->needingAttention())
            ->when($request->integer('client') > 0, fn (Builder $query) => $query->where('client_id', $request->integer('client')))
            ->orderBy($request->integer('expiring') > 0 ? 'expiry_date' : 'id', $request->integer('expiring') > 0 ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/domains/index', [
            'domains' => DomainResource::collection($domains),
            'filters' => $request->only(['search', 'status', 'registrar', 'tld', 'expiring', 'client', 'attention']),
            'statuses' => DomainStatus::values(),
            'registrars' => $registrars->keys(),
            'registrarNames' => $registrars->names(),
            'tlds' => Tld::query()->ordered()->pluck('extension'),
        ]);
    }

    public function show(Request $request, Domain $domain, RegistrarManager $registrars): Response
    {
        $this->authorize('domains.domains.view');
        $domain->load(['client:id,first_name,last_name,company_name', 'contacts']);

        return Inertia::render('admin/domains/show', [
            'domain' => new DomainResource($domain),
            'contacts' => $this->contacts($domain),
            'capabilities' => $registrars->capabilitiesOf($domain->registrar_key),
            'registrar' => $domain->registrar_key === null || ! $registrars->has($domain->registrar_key) ? null : [
                'key' => $domain->registrar_key,
                'name' => $registrars->driver($domain->registrar_key)->name(),
                'active' => $registrars->isActive($domain->registrar_key),
            ],
            'billingAvailable' => app(RenewalInvoices::class)->available(),
            'registrars' => $registrars->keys(),
            'registrarNames' => $registrars->names(),
            'statuses' => DomainStatus::values(),
            'allowedStatuses' => array_map(fn (DomainStatus $status): string => $status->value, $domain->status->allowedTransitions()),
            'commandLog' => $domain->commandLog()->with('staff:id,name')->latest('id')->limit(25)->get()
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'command' => $entry->command,
                    'success' => $entry->success,
                    'error' => $entry->error,
                    'duration_ms' => $entry->duration_ms,
                    'staff' => $entry->staff?->name,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
            'reminders' => $domain->reminders()->latest('id')->limit(20)->get(['id', 'expiry_date', 'offset_days', 'dispatched_at'])
                ->map(fn ($reminder): array => [
                    'id' => $reminder->id,
                    'expiry_date' => $reminder->expiry_date->toDateString(),
                    'offset_days' => $reminder->offset_days,
                    'dispatched_at' => $reminder->dispatched_at->toIso8601String(),
                ]),
            'invoices' => $domain->invoices()->latest('id')->limit(20)->get(['id', 'invoice_id', 'kind', 'period_start', 'years', 'status'])
                ->map(fn ($invoice): array => [...$invoice->only(['id', 'invoice_id', 'kind', 'years', 'status']), 'period_start' => $invoice->period_start->toDateString()]),
            'activity' => ActivityLog::query()
                ->where('subject_type', $domain->getMorphClass())
                ->where('subject_id', $domain->id)
                ->with('user:id,name')
                ->latest('id')
                ->limit(20)
                ->get(['id', 'user_id', 'description', 'created_at'])
                ->map(fn (ActivityLog $entry): array => [
                    'id' => $entry->id,
                    'description' => $entry->description,
                    'user' => $entry->user?->name,
                    'created_at' => $entry->getAttribute('created_at')?->toIso8601String(),
                ]),
            'can' => [
                'update' => $request->user()?->hasPermission('domains.domains.update') ?? false,
                'commands' => $request->user()?->hasPermission('domains.domains.commands') ?? false,
                'delete' => $request->user()?->hasPermission('domains.domains.delete') ?? false,
            ],
        ]);
    }

    public function update(UpdateDomainRequest $request, Domain $domain, UpdateDomainDetails $update): RedirectResponse
    {
        $update->handle($domain, $request->details(), $request->boolean('recalculate_recurring'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain :name updated.', ['name' => $domain->name_unicode])]);

        return to_route('admin.domains.show', $domain);
    }

    /**
     * Soft-delete the record only; the registrar is never called (spec §4.7).
     */
    public function destroy(Domain $domain, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('domains.domains.delete');
        $activity->log("Domain record {$domain->name} deleted", $domain, $domain->client_id);
        $domain->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain record :name deleted.', ['name' => $domain->name_unicode])]);

        return to_route('admin.domains.index');
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function contacts(Domain $domain): array
    {
        $contacts = [];

        foreach (DomainContact::ROLES as $role) {
            $contact = $domain->contacts->firstWhere('role', $role);
            $contacts[$role] = $contact?->only(DomainContact::FIELDS) ?? array_fill_keys(DomainContact::FIELDS, null);
        }

        return $contacts;
    }
}
