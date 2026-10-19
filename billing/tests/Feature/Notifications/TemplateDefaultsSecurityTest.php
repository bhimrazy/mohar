<?php

use App\Models\User;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\TemplateCatalog;

require_once __DIR__.'/helpers.php';

/*
 * Security review of #78 (template default updates): "Use new default" is a template change (sensitive tier, recent
 * password); default_hash and previous_text are written only by the system, never from the request.
 */

it('does not let a module wildcard role use the new default', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'auth.user_invitation')->sole();

    $this->actingAs(User::factory()->staff(['notifications.*'])->create())->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.notifications.templates.restore', $template))->assertForbidden();
});

it('needs a recent password to use the new default', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'auth.user_invitation')->sole();

    $this->actingAs(User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create())
        ->post(route('admin.notifications.templates.restore', $template))->assertSessionHasErrors('password_confirmation');
    expect($template->translations()->sole()->previous_text)->toBeNull();
});

it('ignores default_hash and previous_text sent with a template save', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'client.welcome')->sole();
    $translation = $template->translations()->sole();
    $before = $translation->only(['default_hash', 'previous_text']);

    $this->actingAs(User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('admin.notifications.templates.update', $template), [
            'name' => $template->name, 'locale' => 'en', 'subject' => 'Edited', 'body' => 'Edited body.',
            'default_hash' => TemplateCatalog::hash(['subject' => 'Edited', 'preheader' => null, 'heading' => null, 'body' => 'Edited body.', 'action_label' => null]),
            'previous_text' => ['subject' => 'x', 'preheader' => null, 'heading' => null, 'body' => '<script>alert(1)</script>', 'action_label' => null, 'replaced_at' => 'now'],
        ])->assertRedirect();

    expect($translation->fresh()->only(['default_hash', 'previous_text']))->toBe($before)
        ->and(app(TemplateCatalog::class)->isUnedited($translation->fresh(), 'client.welcome'))->toBeFalse();

    // An upgrade keeps the edit.
    app(TemplateCatalog::class)->install('en');
    expect($translation->fresh()->body)->toBe('Edited body.');
});

it('keeps an edited secret template through an upgrade and restores only shipped text that passes the policy', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'auth.user_invitation')->sole();
    $translation = $template->translations()->sole();
    $translation->update(['body' => 'Our own invitation wording.', 'default_hash' => 'an-older-default']);

    app(TemplateCatalog::class)->install('en');
    expect($translation->fresh()->body)->toBe('Our own invitation wording.');

    $definition = app(TemplateCatalog::class)->find('auth.user_invitation');
    expect(App\Modules\Notifications\Support\SecretTemplatePolicy::violations('auth.user_invitation', TemplateCatalog::shippedText($definition)))->toBe([]);
});
