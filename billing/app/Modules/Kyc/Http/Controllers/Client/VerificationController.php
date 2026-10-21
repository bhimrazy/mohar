<?php

namespace App\Modules\Kyc\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Kyc\Actions\StoreDocument;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Http\Requests\DocumentRequest;
use App\Modules\Kyc\Services\KycVerification;
use App\Modules\Kyc\Services\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's identity verification page: email, phone, then two identity documents. Scoped to the current client
 * account; owners and logins with the profile area may use it. Never sends document numbers, paths or images.
 */
class VerificationController extends Controller
{
    public function __construct(private ClientContext $context, private KycVerification $kyc) {}

    public function show(): Response
    {
        $client = $this->client();
        $documents = $this->kyc->documents($client);

        return Inertia::render('client/kyc/show', [
            'status' => ['value' => ($status = $this->kyc->status($client, $documents))->value, 'label' => $status->label()],
            'email' => [
                'address' => $client->email,
                'done' => $this->kyc->emailProved($client),
            ],
            'phone' => [
                'value' => PhoneNumber::normalise($client->phone, $this->kyc->phoneCountryCode()) ?? $client->phone,
                'done' => $this->kyc->phoneDone($client),
                'countryCode' => $this->kyc->phoneCountryCode(),
            ],
            'documents' => array_map(fn (DocumentType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'hasBack' => $type->hasBack(),
                'current' => ($document = $documents->firstWhere('type', $type)) === null ? null : [
                    'number' => $document->maskedNumber(),
                    'status' => $document->status->value,
                    'statusLabel' => $document->status->label(),
                    'reason' => $document->rejection_reason,
                    'uploadedAt' => $document->created_at?->toIso8601String(),
                ],
            ], DocumentType::cases()),
            'requiredTypes' => KycVerification::REQUIRED_TYPES,
            'maxUploadMb' => intdiv((int) config('kyc.max_upload_kb', 5120), 1024),
        ]);
    }

    public function phone(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $client = $this->client();
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = PhoneNumber::normalise($request->string('phone')->toString(), $this->kyc->phoneCountryCode());

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number, for example :example.', ['example' => $this->kyc->phoneCountryCode() === '+977' ? '+977 9841234567' : $this->kyc->phoneCountryCode().' 5550100'])]);
        }

        [$client] = Client::updateDetails($client->id, ['phone' => $phone], EmailSource::Client);
        $activity->log('Phone number saved for identity verification', $client, $client->id, event: 'kyc.phone.saved');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Phone number saved.')]);

        return to_route('client.kyc.show');
    }

    public function document(DocumentRequest $request, StoreDocument $store): RedirectResponse
    {
        $client = $this->client();
        $type = DocumentType::from($request->string('type')->toString());

        $front = $request->file('front');
        $back = $request->file('back');
        abort_unless($front instanceof UploadedFile, 422);

        $store->handle($client->id, $type, trim($request->string('number')->toString()), $front, $back instanceof UploadedFile ? $back : null, $request->user()?->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':document uploaded. Our team will review it.', ['document' => $type->label()])]);

        return to_route('client.kyc.show');
    }

    private function client(): Client
    {
        abort_unless($this->kyc->enabled(), 404);
        $this->context->authorize('profile');

        return $this->context->client();
    }
}
