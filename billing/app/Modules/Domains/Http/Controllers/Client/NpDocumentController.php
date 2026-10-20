<?php

namespace App\Modules\Domains\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Domains\Http\Requests\NpDocumentRequest;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Services\NpWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The registrant's side of a .np request: upload documents, then send them for staff review. Scoped to the
 * current client account.
 */
class NpDocumentController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function store(NpDocumentRequest $request, int $domain, NpWorkflow $workflow): RedirectResponse
    {
        $workflow->upload($this->registration($domain), $request->string('applicant_type')->toString(), $request->string('kind')->toString(), $request->file('file'), $request->user()?->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return to_route('client.domains.show', $domain);
    }

    public function submit(int $domain, NpWorkflow $workflow): RedirectResponse
    {
        $workflow->sendForReview($this->registration($domain));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Documents sent. We check them and submit your request to the registry.')]);

        return to_route('client.domains.show', $domain);
    }

    private function registration(int $domain): NpRegistration
    {
        $this->context->authorize('domains');
        $model = Domain::query()->where('client_id', $this->context->id())->findOrFail($domain);

        return NpRegistration::query()->where('domain_id', $model->id)->firstOrFail();
    }
}
