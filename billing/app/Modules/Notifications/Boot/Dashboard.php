<?php

namespace App\Modules\Notifications\Boot;

use App\Models\User;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Services\SecretTemplatePolicy;
use App\Support\Dashboard\Dashboard as DashboardRegistry;
use Illuminate\Contracts\Container\Container;

final class Dashboard
{
    public function __invoke(Container $app): void
    {
        $dashboard = $app->make(DashboardRegistry::class);

        $dashboard->todo('admin', 'notifications.failed_emails', fn (?User $user): array => [
            'label' => 'Emails that failed in the last 24 hours',
            'count' => EmailMessage::query()->where('status', EmailStatus::Failed)->where('updated_at', '>=', now()->subDay())->count(),
            'href' => route('admin.notifications.emails.index', ['status' => EmailStatus::Failed->value]),
            'tone' => 'danger',
        ], 'notifications.log.view', 40);

        $dashboard->todo('admin', 'notifications.disabled_webhooks', fn (?User $user): array => [
            'label' => 'Disabled webhook endpoints',
            'count' => WebhookEndpoint::query()->where('is_active', false)->whereNotNull('disabled_reason')->count(),
            'href' => route('admin.notifications.webhooks.index'),
            'tone' => 'warning',
        ], 'notifications.webhooks.manage', 41);

        // Account mail fails closed when APP_URL can't carry account links (e.g. http:// in production): say so.
        $dashboard->health('admin', 'notifications.account_links', function (?User $user): array {
            $problem = SecretTemplatePolicy::accountLinkProblem();

            return [
                'label' => 'Account email',
                'detail' => $problem ?? 'Reset and verification links use '.config('app.url'),
                'value' => $problem === null ? 'OK' : 'Held',
                'tone' => $problem === null ? 'success' : 'danger',
                'icon' => 'Mail',
            ];
        }, 'notifications.log.view', 60);
    }
}
