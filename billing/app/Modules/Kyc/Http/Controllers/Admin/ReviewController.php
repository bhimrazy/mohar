<?php

namespace App\Modules\Kyc\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycVerification;
use App\Modules\Kyc\Services\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Staff review of identity documents (spec §7). Every action needs kyc.documents.review, a sensitive permission.
 * Images are shown only after a password step-up (unlock) and only through signed links that expire in 15 minutes.
 */
class ReviewController extends Controller
{
    public const PERMISSION = 'kyc.documents.review';

    /** Minutes a signed image link, and an unlock, stay valid. */
    public const LINK_MINUTES = 15;

    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize(self::PERMISSION);

        if ($request->filled('client')) {
            return to_route('admin.kyc.show', $request->integer('client'));
        }

        // kyc_documents_queue_index: status + superseded_at, ordered by the oldest waiting upload.
        $queue = KycDocument::query()->current()->where('status', DocumentStatus::Pending->value)
            ->groupBy('client_id')->selectRaw('client_id, min(created_at) as waiting_since, count(*) as waiting')
            ->orderBy('waiting_since')->orderBy('client_id')->paginate(25)->withQueryString();
        $clients = Client::query()->whereIn('id', $queue->pluck('client_id'))->get()->keyBy('id');

        return Inertia::render('admin/kyc/index', [
            'queue' => $queue->through(fn (KycDocument $row): array => [
                'client' => ['id' => (int) $row->client_id, ...$clients->has((int) $row->client_id) ? ['name' => $clients->get((int) $row->client_id)->display_name, 'email' => $clients->get((int) $row->client_id)->email] : ['name' => '', 'email' => null]],
                'waiting' => (int) $row->getAttribute('waiting'),
                'waitingSince' => $row->getAttribute('waiting_since') === null ? null : CarbonImmutable::parse((string) $row->getAttribute('waiting_since'), 'UTC')->toIso8601String(),
            ]),
        ]);
    }

    public function show(Request $request, Client $client, KycVerification $kyc): Response
    {
        $this->authorize(self::PERMISSION);
        $documents = $kyc->documents($client);
        $unlocked = (int) $request->session()->get('kyc.unlocked_until', 0) > now()->getTimestamp();
        $status = $kyc->status($client, $documents);

        return Inertia::render('admin/kyc/show', [
            'client' => ['id' => $client->id, 'name' => $client->display_name, 'email' => $client->email, 'phone' => PhoneNumber::normalise($client->phone, $kyc->phoneCountryCode()) ?? $client->phone],
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'steps' => ['email' => $kyc->emailProved($client), 'phone' => $kyc->phoneDone($client)],
            'unlocked' => $unlocked,
            'documents' => $documents->map(fn (KycDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type->label(),
                'status' => $document->status->value,
                'statusLabel' => $document->status->label(),
                'reason' => $document->rejection_reason,
                'uploadedAt' => $document->created_at?->toIso8601String(),
                'decidedAt' => $document->decided_at?->toIso8601String(),
                'version' => DecideDocument::version($document),
                // The number and images only after the step-up; images only through short-lived signed links.
                'number' => $unlocked ? $document->number : null,
                'images' => $unlocked ? $this->images($document) : [],
            ])->values()->all(),
        ]);
    }

    /**
     * Step-up (password.recent on the route), then show the numbers and images for LINK_MINUTES.
     */
    public function unlock(Request $request, Client $client, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize(self::PERMISSION);
        $request->session()->put('kyc.unlocked_until', now()->addMinutes(self::LINK_MINUTES)->getTimestamp());
        $activity->log('Identity documents opened for review', $client, $client->id, event: 'kyc.documents.opened');

        return to_route('admin.kyc.show', $client);
    }

    /**
     * Stream one decrypted image. The link is signed and expires; the permission and step-up are checked again here.
     */
    public function image(KycDocument $document, string $side, ActivityLogger $activity): HttpResponse
    {
        $this->authorize(self::PERMISSION);
        $path = $side === 'back' ? $document->back_path : $document->front_path;
        abort_if($path === null, 404);

        try {
            $contents = Crypt::decryptString((string) Storage::disk(KycDocument::DISK)->get($path));
        } catch (DecryptException) {
            abort(410, __('This image can no longer be decrypted (the application key changed). Ask the client to upload it again.'));
        }

        $activity->log('Identity document viewed', $document, $document->client_id, ['type' => $document->type->value, 'side' => $side], 'kyc.document.viewed');

        return response($contents, 200, [
            'Content-Type' => $side === 'back' ? (string) $document->back_mime : $document->front_mime,
            'Content-Disposition' => 'inline; filename="document-'.$document->id.'-'.$side.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request, KycDocument $document, DecideDocument $decide): RedirectResponse
    {
        $this->authorize(self::PERMISSION);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:500'],
            'version' => ['required', 'string', 'max:64'],
        ]);
        $staff = $request->user();
        abort_unless($staff instanceof User, 403);

        $decided = $decide->handle($document->id, $data['version'], $data['decision'] === 'approve', isset($data['reason']) ? trim((string) $data['reason']) : null, $staff);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'approve'
            ? __(':document approved.', ['document' => $decided->type->label()])
            : __(':document rejected. The client sees your reason and can upload it again.', ['document' => $decided->type->label()])]);

        return to_route('admin.kyc.show', $decided->client_id);
    }

    /**
     * @return list<array{side: string, label: string, url: string}>
     */
    private function images(KycDocument $document): array
    {
        $images = [['side' => 'front', 'label' => 'Front', 'url' => $this->link($document, 'front')]];

        if ($document->back_path !== null) {
            $images[] = ['side' => 'back', 'label' => 'Back', 'url' => $this->link($document, 'back')];
        }

        return $images;
    }

    private function link(KycDocument $document, string $side): string
    {
        return URL::temporarySignedRoute('admin.kyc.image', now()->addMinutes(self::LINK_MINUTES), ['document' => $document->id, 'side' => $side]);
    }
}
