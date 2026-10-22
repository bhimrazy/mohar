<?php

namespace App\Modules\Kyc\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\KycStatus;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycVerification;
use App\Modules\Kyc\Services\PhoneNumber;
use App\Modules\Kyc\Services\ReviewQueue;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Staff review of identity documents (spec §7). Viewing needs kyc.documents.view and deciding kyc.documents.review; both are sensitive permissions.
 * Numbers and images show only for a client the staff member unlocked (password step-up, audited), and images only
 * through signed links bound to that staff member, inside the same window.
 */
class ReviewController extends Controller
{
    /** Approve or decline (owner decision 2026-10-21: full administrators only by default). */
    public const PERMISSION = 'kyc.documents.review';

    /** See the queue, a client's page and, after a step-up, the documents (Support has it by default). */
    public const VIEW_PERMISSION = 'kyc.documents.view';

    public function __construct(private KycVerification $kyc, private ReviewUnlocks $unlocks) {}

    public function index(Request $request, ReviewQueue $queue): Response|RedirectResponse
    {
        $this->authorizeView($request);

        if ($request->filled('client')) {
            return to_route('admin.kyc.show', $request->integer('client'));
        }

        $filter = in_array($request->query('status'), ReviewQueue::FILTERS, true) ? (string) $request->query('status') : 'pending_review';
        $search = trim($request->string('q')->toString());
        $clients = $queue->clients($filter)->search($search === '' ? null : $search)->orderByDesc('id')->paginate(25)->withQueryString();
        $documents = KycDocument::query()->whereIn('client_id', $clients->pluck('id'))->current()->orderBy('id')->get()->groupBy('client_id');

        return Inertia::render('admin/kyc/index', [
            'counts' => $queue->counts(),
            'filters' => ['status' => $filter, 'q' => $search],
            'clients' => $clients->through(function (Client $client) use ($documents): array {
                $own = $documents->get($client->id, collect());
                $status = $this->kyc->status($client, $own);
                $waiting = $own->where('status', DocumentStatus::Pending);

                return [
                    'id' => $client->id,
                    'name' => $client->display_name,
                    'email' => $client->email,
                    'status' => ['value' => $status->value, 'label' => $status->label()],
                    'waiting' => $waiting->count(),
                    'waitingSince' => $waiting->min('created_at')?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorizeView($request);
        // Encrypted, and cleared when the unlock ends (ClearEndedReviewHistory) or is locked again (lock()).
        Inertia::encryptHistory();
        $documents = $this->kyc->documents($client);
        $until = $this->unlocks->until($request, $client->id);
        $status = $this->kyc->status($client, $documents);

        return Inertia::render('admin/kyc/show', [
            'client' => ['id' => $client->id, 'name' => $client->display_name, 'email' => $client->email, 'phone' => PhoneNumber::normalise($client->phone, $this->kyc->phoneCountryCode()) ?? $client->phone],
            'status' => [
                'value' => $status->value,
                'label' => $status->label(),
                'verifiedAt' => $status === KycStatus::Verified ? $this->kyc->verifiedAt($documents)?->toIso8601String() : null,
            ],
            'steps' => ['email' => $this->kyc->emailProved($client), 'phone' => $this->kyc->phoneDone($client)],
            'unlockedUntil' => $until?->toIso8601String(),
            'canDecide' => $request->user()?->hasPermission(self::PERMISSION) ?? false,
            'documents' => $documents->map(fn (KycDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type->label(),
                'status' => $document->status->value,
                'statusLabel' => $document->status->label(),
                'reason' => $document->decline_reason,
                'uploadedAt' => $document->created_at?->toIso8601String(),
                'decidedAt' => $document->decided_at?->toIso8601String(),
                'version' => DecideDocument::version($document),
                // kyc:reencode-images could not re-encode these images, so they may still carry EXIF (GPS, device).
                'metadataKept' => $document->sanitise_issue !== null && $document->sanitised_at === null,
                // The number and images only for an unlocked client; images only through short-lived signed links.
                'number' => $until !== null ? $document->number : null,
                'images' => $until !== null ? $this->images($request, $document, $until) : [],
            ])->values()->all(),
        ]);
    }

    /**
     * Step-up (password.recent on the route), then show this client's numbers and images until the window ends.
     */
    public function unlock(Request $request, Client $client, ActivityLogger $activity): RedirectResponse
    {
        $this->authorizeView($request);
        $this->unlocks->unlock($request, $client->id);
        $activity->log('Identity documents opened for review', $client, $client->id, event: 'kyc.documents.opened');

        return to_route('admin.kyc.show', $client);
    }

    /**
     * Hide this client's numbers and images before the window ends, and clear the browser history that holds them.
     * No step-up: it only takes access away.
     */
    public function lock(Request $request, Client $client, ActivityLogger $activity): RedirectResponse
    {
        $this->authorizeView($request);

        if ($this->unlocks->lock($request, $client->id)) {
            Inertia::clearHistory();
            $activity->log('Identity documents hidden again', $client, $client->id, event: 'kyc.documents.closed');
        }

        return to_route('admin.kyc.show', $client);
    }

    /**
     * Stream one decrypted image: the signature (bound to this staff member), the permission and this client's unlock
     * are all checked here.
     */
    public function image(Request $request, KycDocument $document, string $side, ActivityLogger $activity): HttpResponse
    {
        $this->authorizeView($request);
        abort_unless((int) $request->query('staff') === (int) $request->user()?->getAuthIdentifier(), 403);
        abort_if($this->unlocks->until($request, $document->client_id) === null, 403, __('Open the documents again to see this image.'));
        $path = $side === 'back' ? $document->back_path : $document->front_path;
        abort_if($path === null, 404);

        try {
            $stored = Storage::disk(KycDocument::DISK)->get($path);
            $contents = $stored === null ? null : Crypt::decryptString($stored);
        } catch (Throwable $exception) {
            // The document id only: storage messages carry the path (spec §5).
            Log::warning('An identity image could not be read.', ['document_id' => $document->id, 'error' => $exception::class]);
            $contents = null;
        }

        abort_if($contents === null, 404, __('This image could not be opened. Ask the client to upload the document again.'));

        $activity->log('Identity document viewed', $document, $document->client_id, ['type' => $document->type->value, 'side' => $side], 'kyc.document.viewed');

        return response($contents, 200, [
            'Content-Type' => $side === 'back' ? (string) $document->back_mime : $document->front_mime,
            'Content-Disposition' => 'inline; filename="'.Str::slug($document->type->label()).'-'.$side.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request, KycDocument $document, DecideDocument $decide): RedirectResponse
    {
        $this->authorize(self::PERMISSION);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'decline'])],
            'reason' => ['nullable', 'required_if:decision,decline', 'string', 'max:500'],
            'version' => ['required', 'string', 'max:64'],
        ], [
            'reason.required_if' => __('Tell the client why, so they know what to fix.'),
            'reason.max' => __('Keep the reason under 500 characters.'),
        ]);
        $staff = $request->user();
        abort_unless($staff instanceof User, 403);

        if ($this->unlocks->until($request, $document->client_id) === null) {
            return back()->withErrors(['document' => __('Open the documents first, then decide.')]);
        }

        $decided = $decide->handle($document->id, $data['version'], $data['decision'] === 'approve', isset($data['reason']) ? trim((string) $data['reason']) : null, $staff);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'approve'
            ? __(':document approved. We emailed the client.', ['document' => $decided->type->label()])
            : __(':document declined. We emailed the client the reason.', ['document' => $decided->type->label()])]);

        return to_route('admin.kyc.show', $decided->client_id);
    }

    /**
     * Viewing needs kyc.documents.view; a reviewer (kyc.documents.review) can view too.
     */
    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission(self::VIEW_PERMISSION) || $user->hasPermission(self::PERMISSION)), 403);
    }

    /**
     * @return list<array{side: string, label: string, url: string}>
     */
    private function images(Request $request, KycDocument $document, CarbonImmutable $until): array
    {
        $link = fn (string $side): string => URL::temporarySignedRoute('admin.kyc.image', $until, ['document' => $document->id, 'side' => $side, 'staff' => (int) $request->user()?->getAuthIdentifier()]);
        $images = [['side' => 'front', 'label' => 'Front', 'url' => $link('front')]];

        if ($document->back_path !== null) {
            $images[] = ['side' => 'back', 'label' => 'Back', 'url' => $link('back')];
        }

        return $images;
    }
}
