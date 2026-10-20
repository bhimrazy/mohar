<?php

namespace App\Modules\Domains\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Domains\Http\Requests\NpStepRequest;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Services\NpWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff steps of a .np request and the registrant's documents (signed, short-lived links; staff only).
 */
class NpRegistrationController extends Controller
{
    public function step(NpStepRequest $request, Domain $domain, string $action, NpWorkflow $workflow): RedirectResponse
    {
        $registration = NpRegistration::query()->where('domain_id', $domain->id)->firstOrFail();
        $approved = $request->boolean('approved');
        $error = null;

        match ($action) {
            'review' => $workflow->review($registration, $approved, $request->validated('reasons'), array_keys(array_filter((array) $request->validated('checklist', [])))),
            'submitted' => $workflow->markSubmitted($registration, CarbonImmutable::parse($request->string('submitted_on')->toString())),
            'decision' => $error = $workflow->decide(
                $registration,
                $approved,
                $request->filled('registry_expires_on') ? CarbonImmutable::parse($request->string('registry_expires_on')->toString()) : null,
                $request->filled('rejection_code') ? $request->integer('rejection_code') : null,
                $request->validated('note'),
                $request->user()?->id,
            ),
            'extend' => $workflow->extend($registration),
            default => $workflow->confirmReview($registration),
        };

        if ($error !== null) {
            return to_route('admin.domains.show', $domain)->withErrors(['registrar' => $error]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($action) {
            'review' => $approved ? __('Documents approved. Submit the request in the registry portal next.') : __('Documents returned to the client with your reasons.'),
            'submitted' => __('Recorded as submitted to the registry.'),
            'decision' => $approved ? __('Approved: the domain is active.') : __('Rejection recorded. Resubmit within two business days.'),
            'extend' => __('Kept open: the client has another 90 days to send the documents.'),
            default => __('Registry status confirmed.'),
        }]);

        return to_route('admin.domains.show', $domain);
    }

    /**
     * "Open" on the domain page: a fresh signed link each time, so a page left open longer than the link's 15 minutes
     * still opens the document.
     */
    public function open(Domain $domain, NpDocument $document): RedirectResponse
    {
        $this->authorize('domains.np.review');
        abort_unless($document->registration->domain_id === $domain->id, 404);

        return redirect()->to(URL::temporarySignedRoute('admin.domains.np.document', now()->addMinutes(15), ['domain' => $domain->id, 'document' => $document->id]));
    }

    /**
     * Stream a document to staff. The link is signed and expires; the permission is checked again here.
     */
    public function document(Domain $domain, NpDocument $document): Response
    {
        $this->authorize('domains.np.review');
        abort_unless($document->registration->domain_id === $domain->id, 404);

        try {
            $contents = Crypt::decryptString((string) Storage::disk(NpDocument::DISK)->get($document->path));
        } catch (DecryptException) {
            abort(410, __('This document can no longer be decrypted (the application key changed). Ask the client to upload it again.'));
        }

        return response($contents, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => 'inline; filename="document-'.$document->id.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
