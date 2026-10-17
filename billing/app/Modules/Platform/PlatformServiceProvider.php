<?php

namespace App\Modules\Platform;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Platform\Http\Middleware\EnforceStaffSecurity;
use App\Modules\Platform\Http\Middleware\EnsureActiveUser;
use App\Modules\Platform\Http\Middleware\HandleImpersonation;
use App\Modules\Platform\Listeners\RecordAuthenticationActivity;
use App\Modules\Platform\Services\Impersonation;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;

class PlatformServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'platform';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(PermissionGuard::class);
        $this->app->singleton(SettingsCatalog::class);
        $this->app->scoped(Impersonation::class);
    }

    protected function bootModule(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', EnsureActiveUser::class);
        $kernel->appendMiddlewareToGroup('web', HandleImpersonation::class);
        $kernel->appendMiddlewareToGroup('web', EnforceStaffSecurity::class);

        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'login']);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'failed']);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'logout']);

        User::saving(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
                $user->must_change_password = false;
            }
        });

        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Activity log', 'admin.platform.activity.index', 'Activity', 'General', 50, 'core.activity_log.view', 'admin.platform.activity.*'));
        $navigation->add('admin', new NavItem('Staff', 'admin.platform.staff.index', 'UserCog', 'Setup', 10, 'core.staff.view', 'admin.platform.staff.*'));
        $navigation->add('admin', new NavItem('Roles', 'admin.platform.roles.index', 'Shield', 'Setup', 20, 'core.roles.manage', 'admin.platform.roles.*'));
        $navigation->add('admin', new NavItem('Currencies', 'admin.platform.currencies.index', 'Coins', 'Setup', 30, 'core.currencies.manage', 'admin.platform.currencies.*'));
        $navigation->add('admin', new NavItem('Client groups', 'admin.platform.client-groups.index', 'Tag', 'Setup', 40, 'core.client_groups.manage', 'admin.platform.client-groups.*'));
        $navigation->add('admin', new NavItem('Settings', 'admin.platform.settings.edit', 'Settings', 'Setup', 80, 'core.settings.view', 'admin.platform.settings.*'));
        $navigation->add('admin', new NavItem('Automation', 'admin.platform.automation.index', 'Timer', 'Setup', 90, 'core.automation.view', 'admin.platform.automation.*'));
    }

    private function registerPermissions(): void
    {
        $permissions = $this->app->make(PermissionRegistry::class);

        $permissions->register('Clients', [
            'core.clients.close' => 'Close and reopen clients',
            'core.clients.merge' => 'Merge clients',
            'core.clients.impersonate' => 'Sign in as a client',
            'core.contacts.manage' => 'Manage client contacts',
            'core.users.manage' => 'Manage client logins',
        ]);

        $permissions->register('General', [
            'core.activity_log.view' => 'View the activity log',
        ]);

        $permissions->register('Setup', [
            'core.staff.view' => 'View staff',
            'core.staff.manage' => 'Invite, edit and deactivate staff',
            'core.roles.manage' => 'Manage roles and permissions',
            'core.currencies.manage' => 'Manage currencies and exchange rates',
            'core.client_groups.manage' => 'Manage client groups',
            'core.settings.view' => 'View settings',
            'core.settings.update' => 'Change general settings',
            'core.automation.view' => 'View the automation log',
            'core.automation.run' => 'Run automation manually',
        ]);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->section('security', 'Security', [
            new SettingDefinition('security.staff_2fa_required', 'Require two-factor authentication for staff', 'boolean', 'Staff without 2FA must set it up before they can use the admin area.'),
            new SettingDefinition('platform.impersonation_minutes', 'Sign-in-as-client time limit (minutes)', 'integer', 'Staff are returned to the admin area after this long.', rules: ['min:5', 'max:240']),
            new SettingDefinition('platform.invitation_expiry_days', 'Staff invitation validity (days)', 'integer', rules: ['min:1', 'max:30']),
        ], 'core.settings.update');
    }

    private function registerSearch(): void
    {
        $search = $this->app->make(GlobalSearch::class);

        $search->provider('Contacts', fn (string $term): array => array_values(Contact::query()
            ->with('client:id,first_name,last_name,company_name')
            ->where(fn ($query) => $query->whereLike('email', $term.'%')->orWhereLike('first_name', $term.'%')->orWhereLike('last_name', $term.'%'))
            ->orderBy('first_name')->orderBy('id')->limit(5)->get()
            ->map(fn (Contact $contact): array => [
                'title' => trim("{$contact->first_name} {$contact->last_name}"),
                'subtitle' => $contact->email.' · '.$contact->client->display_name,
                'href' => route('admin.clients.show', $contact->client_id),
            ])->all()), 'core.clients.view');

        $search->provider('Staff', fn (string $term): array => array_values(User::query()
            ->where('is_staff', true)
            ->where(fn ($query) => $query->whereLike('name', $term.'%')->orWhereLike('email', $term.'%'))
            ->orderBy('name')->orderBy('id')->limit(5)->get()
            ->map(fn (User $user): array => [
                'title' => $user->name,
                'subtitle' => $user->email,
                'href' => route('admin.platform.staff.edit', $user),
            ])->all()), 'core.staff.manage');
    }
}
