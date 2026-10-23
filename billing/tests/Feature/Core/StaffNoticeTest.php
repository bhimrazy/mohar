<?php

use App\Models\User;
use App\Modules\Core\Models\StaffNotice;
use Inertia\Testing\AssertableInertia as Assert;

function noticeFor(User $user, bool $dismissed = false): StaffNotice
{
    return StaffNotice::query()->create([
        'user_id' => $user->id,
        'notice' => StaffNotice::COMPANY_TIME_SETTINGS,
        'dismissed_at' => $dismissed ? now() : null,
    ]);
}

it('shows the company-time notice to staff who can change settings until they dismiss it', function () {
    $user = actingAsStaff(['core.settings.update']);
    noticeFor($user);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('notices', 1)
        ->where('notices.0.key', 'company_time_settings')
        ->where('notices.0.body', fn (string $body) => str_contains($body, 'company time zone (UTC)') && str_contains($body, 'did not change either value') && ! str_contains($body, 'server'))
        ->has('notices.0.links', 1)
        ->where('notices.0.links.0.label', 'Automation time'));

    $this->post(route('admin.notices.dismiss', 'company_time_settings'))->assertRedirect();

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->has('notices', 0));
});

it('links Rate update time only to staff who can change exchange rates', function () {
    $user = actingAsStaff(['core.settings.update', 'core.currencies.rates']);
    noticeFor($user);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('notices.0.links', 2)
        ->where('notices.0.links.1.label', 'Rate update time')
        ->where('notices.0.links.1.href', route('admin.platform.settings.edit', 'exchange_rates')));
});

it('returns 404 for a notice that does not exist', function () {
    actingAsStaff();

    $this->post(route('admin.notices.dismiss', 'no_such_notice'))->assertNotFound();
});

it('dismisses the notice for the signed-in person only', function () {
    $other = User::factory()->staff(['core.settings.update'])->create();
    noticeFor($other);
    $user = actingAsStaff(['core.settings.update']);
    noticeFor($user);

    $this->post(route('admin.notices.dismiss', 'company_time_settings'));

    expect($user->fresh()->id)->toBe($user->id)
        ->and(StaffNotice::query()->where('user_id', $other->id)->whereNull('dismissed_at')->count())->toBe(1)
        ->and(StaffNotice::query()->where('user_id', $user->id)->whereNull('dismissed_at')->count())->toBe(0);
});

it('hides the notice from staff without the settings permission and from people who never had it', function () {
    $reader = actingAsStaff(['core.clients.view']);
    noticeFor($reader);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->has('notices', 0));
    $this->post(route('admin.notices.dismiss', 'company_time_settings'))->assertForbidden();

    actingAsStaff(['core.settings.update']); // hired after the upgrade: no row

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->has('notices', 0));
});

it('puts a daylight-saving help line on the automation time setting', function () {
    actingAsStaff();

    $this->get(route('admin.platform.settings.edit', 'company'))
        ->assertInertia(fn (Assert $page) => $page->where('section.fields', fn ($settings) => collect($settings)->contains(
            fn ($setting) => $setting['key'] === 'core.automation_time' && str_contains($setting['help'], 'daylight saving') && str_contains($setting['help'], 'Nepal time are never skipped'),
        )));
});
