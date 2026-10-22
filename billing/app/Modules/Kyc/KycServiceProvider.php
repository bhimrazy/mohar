<?php

namespace App\Modules\Kyc;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Events\Core\ClientClosed;
use App\Events\Core\ClientMerged;
use App\Events\Core\ClientReopened;
use App\Models\User;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Kyc\Actions\PruneDocuments;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\KycStatus;
use App\Modules\Kyc\Http\Controllers\Admin\ReviewController;
use App\Modules\Kyc\Http\Middleware\ShareKycBanner;
use App\Modules\Kyc\Listeners\ScheduleDocumentRetention;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycVerification;
use App\Support\Automation\Automation;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Security\EncryptedFiles;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;

/**
 * Client identity verification (spec docs/product/kyc.md): email, phone and two identity documents, reviewed by
 * staff, never blocking the client. Uses Core (clients, settings, audit) and kernel events only.
 */
class KycServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'kyc';
    }

    protected function registerModule(): void
    {
        $this->app->scoped(KycVerification::class);
    }

    protected function bootModule(): void
    {
        // The document number (encrypted cast) and the identity images on the objects disk, for `security:reencrypt`
        // (ADR 0022). A path switch locks the client, then the document (ADR 0021 §3).
        $encrypted = $this->app->make(EncryptedStorage::class);
        $encrypted->register('kyc_documents', new EncryptedModel(KycDocument::class));
        $encrypted->register('kyc_documents_files', new EncryptedFiles('kyc_documents', ['front_path', 'back_path'], KycDocument::DISK, lockFirst: ['clients', 'client_id']));

        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ShareKycBanner::class);

        // A document number never reaches the session, also when an action (not the form request) refuses the upload.
        $this->callAfterResolving(ExceptionHandler::class, function (object $handler): void {
            if (method_exists($handler, 'dontFlash')) {
                $handler->dontFlash(['number']);
            }
        });

        Event::listen([ClientClosed::class, ClientReopened::class, ClientMerged::class], ScheduleDocumentRetention::class);

        $this->app->make(PermissionRegistry::class)->register('Identity verification', [
            'kyc.settings.manage' => 'Manage identity verification settings',
            ReviewController::VIEW_PERMISSION => 'View the identity verification queue and identity documents',
            ReviewController::PERMISSION => 'Approve or decline identity documents',
        ], sensitive: [ReviewController::VIEW_PERMISSION, ReviewController::PERMISSION]);

        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Identity verification', 'admin.kyc.index', 'ShieldCheck', 'Clients', 40, ReviewController::VIEW_PERMISSION, 'admin.kyc.*',
            badge: fn (?User $user): ?int => RequestMemo::remember('kyc.waiting', fn (): int => KycDocument::query()->current()->where('status', DocumentStatus::Pending->value)->distinct()->count('client_id')) ?: null));
        $navigation->add('admin-client', new NavItem('Identity verification', 'admin.kyc.index', 'ShieldCheck', order: 70, permission: ReviewController::VIEW_PERMISSION));

        // Merge fields for the decision email: outcome, type, reason and link; never the number or the images.
        $this->app->make(MergeFieldRegistry::class)->register('kyc_document', function (int $id): array {
            $document = KycDocument::query()->with('client')->findOrFail($id);
            $kyc = $this->app->make(KycVerification::class);

            return [
                'client_id' => $document->client_id,
                'document' => $document->type->label(),
                'approved' => $document->status === DocumentStatus::Approved,
                'verified' => $kyc->status($document->client) === KycStatus::Verified,
                'reason' => $document->decline_reason,
                'url' => route('client.kyc.show'),
            ];
        });

        $this->app->make(SettingsRegistry::class)->section('kyc', 'Identity verification', [
            new SettingDefinition('kyc.enabled', 'Ask clients to verify their identity', 'boolean', 'Shows a banner and a verification page in the client area. It never blocks ordering or paying.'),
            new SettingDefinition('kyc.phone_country_code', 'Default phone country code', help: 'Added to numbers entered without one, for example +977.', rules: ['regex:/^\+\d{1,4}$/']),
        ], 'kyc.settings.manage');

        $this->app->make(Automation::class)->task('kyc.prune_documents', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneDocuments::class)->handle($today),
            label: 'Delete identity documents past retention', description: 'Deletes replaced identity documents, and those of accounts closed 90 days ago.');
    }
}
