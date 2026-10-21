<?php

namespace App\Modules\Kyc;

use App\Events\Core\ClientClosed;
use App\Events\Core\ClientMerged;
use App\Events\Core\ClientReopened;
use App\Models\User;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Kyc\Actions\PruneDocuments;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Http\Controllers\Admin\ReviewController;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Http\Middleware\ShareKycBanner;
use App\Modules\Kyc\Listeners\ScheduleDocumentRetention;
use App\Modules\Kyc\Services\KycVerification;
use App\Support\Automation\Automation;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
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
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ShareKycBanner::class);

        Event::listen([ClientClosed::class, ClientReopened::class, ClientMerged::class], ScheduleDocumentRetention::class);

        $this->app->make(PermissionRegistry::class)->register('Identity verification', [
            'kyc.settings.manage' => 'Manage identity verification settings',
            ReviewController::PERMISSION => 'View identity documents and approve or reject them',
        ], sensitive: [ReviewController::PERMISSION]);

        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Identity verification', 'admin.kyc.index', 'ShieldCheck', 'Clients', 40, ReviewController::PERMISSION, 'admin.kyc.*',
            badge: fn (?User $user): ?int => RequestMemo::remember('kyc.waiting', fn (): int => KycDocument::query()->current()->where('status', DocumentStatus::Pending->value)->distinct()->count('client_id')) ?: null));
        $navigation->add('admin-client', new NavItem('Identity verification', 'admin.kyc.index', 'ShieldCheck', order: 70, permission: ReviewController::PERMISSION));

        $this->app->make(SettingsRegistry::class)->section('kyc', 'Identity verification', [
            new SettingDefinition('kyc.enabled', 'Ask clients to verify their identity', 'boolean', 'Shows a banner and a verification page in the client area. It never blocks ordering or paying.'),
            new SettingDefinition('kyc.phone_country_code', 'Default phone country code', help: 'Added to numbers entered without one, for example +977.', rules: ['regex:/^\+\d{1,4}$/']),
        ], 'kyc.settings.manage');

        $this->app->make(Automation::class)->task('kyc.prune_documents', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneDocuments::class)->handle($today),
            label: 'Delete identity documents past retention', description: 'Deletes replaced identity documents, and those of accounts closed 90 days ago.');
    }
}
