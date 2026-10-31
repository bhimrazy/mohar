<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Services\TemplateCatalog;

require_once __DIR__.'/helpers.php';

/*
 * Template text and upgrades (CTO decision 2026-10-19): text nobody edited follows the shipped default; edited text
 * is kept and the editor offers the new default side by side, keeping a copy when staff switch.
 */

function welcomeText(): EmailTemplateTranslation
{
    return EmailTemplate::query()->where('key', 'client.welcome')->sole()->translations()->sole();
}

it('updates text that still matches an earlier shipped default, with an audit entry, and leaves the rest alone', function () {
    seedEmailTemplates();
    $catalog = app(TemplateCatalog::class);
    $legacy = require base_path('tests/Fixtures/legacy-welcome-template.php');
    welcomeText()->update([...$legacy, 'default_hash' => null]);
    EmailTemplate::query()->where('key', 'invoice.created')->sole()->translations()->update(['body' => 'Our own wording.', 'default_hash' => null]);

    $result = $catalog->install('en');

    expect($result->updated)->toBe(1)
        ->and(welcomeText()->body)->toBe($catalog->find('client.welcome')['body'])
        ->and(welcomeText()->default_hash)->toBe(TemplateCatalog::hash(TemplateCatalog::shippedText($catalog->find('client.welcome'))))
        ->and(EmailTemplate::query()->where('key', 'invoice.created')->sole()->translations()->sole()->body)->toBe('Our own wording.')
        ->and(ActivityLog::query()->where('event', 'notifications.template_default_updated')->count())->toBe(1)
        ->and($catalog->install('en')->updated)->toBe(0);
});

it('follows a changed default when the text was never edited since its tracked default', function () {
    seedEmailTemplates();
    $old = ['subject' => 'Old subject', 'preheader' => null, 'heading' => null, 'body' => "# Old\n\nOld text.", 'action_label' => null];
    welcomeText()->update([...$old, 'default_hash' => TemplateCatalog::hash($old)]);

    app(TemplateCatalog::class)->install('en');

    expect(welcomeText()->subject)->not->toBe('Old subject');
});

it('offers the new default for edited text, side by side, and keeps the edited text when staff switch', function () {
    seedEmailTemplates();
    $staff = User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create();
    $template = EmailTemplate::query()->where('key', 'client.welcome')->sole();
    welcomeText()->update(['body' => 'Our own wording.', 'default_hash' => 'an-older-default']);

    $this->actingAs($staff)->get(route('admin.notifications.templates.edit', $template))
        ->assertInertia(fn ($page) => $page->where('newDefault.status', 'changed')->where('newDefault.text.body', app(TemplateCatalog::class)->find('client.welcome')['body'])->where('translation.previous_text', null));

    $this->actingAs($staff)->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->post(route('admin.notifications.templates.restore', $template))->assertRedirect();

    expect(welcomeText()->previous_text['body'])->toBe('Our own wording.')
        ->and(welcomeText()->body)->toBe(app(TemplateCatalog::class)->find('client.welcome')['body']);
    $this->actingAs($staff)->get(route('admin.notifications.templates.edit', $template))
        ->assertInertia(fn ($page) => $page->where('newDefault', null)->where('translation.previous_text.body', 'Our own wording.'));
});

it('does not offer a new default to text edited from the current default', function () {
    seedEmailTemplates();
    welcomeText()->update(['body' => 'Our own wording.']);

    expect(app(TemplateCatalog::class)->newDefaultStatus(welcomeText(), 'client.welcome'))->toBeNull();
});

it('offers only a comparison for text edited before tracking, and only where this release changed the default', function () {
    seedEmailTemplates();
    $catalog = app(TemplateCatalog::class);
    welcomeText()->update(['body' => 'Our own wording.', 'default_hash' => null]);
    $invitation = EmailTemplate::query()->where('key', 'auth.user_invitation')->sole()->translations()->sole();
    $invitation->update(['body' => 'Our own invitation wording.', 'default_hash' => null]);

    expect($catalog->newDefaultStatus(welcomeText(), 'client.welcome'))->toBeNull()
        ->and($catalog->newDefaultStatus($invitation->fresh(), 'auth.user_invitation'))->toBe('untracked');
});

it('counts whitespace changes as edits, except line endings and trailing whitespace', function () {
    $text = ['subject' => 'Hi', 'preheader' => null, 'heading' => null, 'body' => "Line one\nLine two", 'action_label' => null];

    expect(TemplateCatalog::hash([...$text, 'body' => "Line one\r\nLine two\n\n"]))->toBe(TemplateCatalog::hash($text))
        ->and(TemplateCatalog::hash([...$text, 'body' => "Line one\n\nLine two"]))->not->toBe(TemplateCatalog::hash($text))
        ->and(TemplateCatalog::hash([...$text, 'body' => "  Line one\nLine two"]))->not->toBe(TemplateCatalog::hash($text));
});

it('does not let a view-only user use the new default', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'client.welcome')->sole();

    $this->actingAs(User::factory()->staff(['notifications.templates.view'])->create())->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->post(route('admin.notifications.templates.restore', $template))->assertForbidden();
});
