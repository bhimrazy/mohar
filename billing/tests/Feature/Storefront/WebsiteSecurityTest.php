<?php

use App\Events\Storefront\StorefrontPublished;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Services\ContentStore;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\KbArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Security review of #99 (Admin > Website) and two parts of #94 (JSON-LD, guest filtering). Each test pins a defence;
 * the ones marked FINDING fail at 3ace11ee and pass once the finding is fixed.
 */

beforeEach(function (): void {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
});

function secWebsiteForm(array $overrides = []): array
{
    return array_replace_recursive(SiteContent::defaults(), ['hero' => ['headline' => 'Reliable hosting for your website']], $overrides);
}

function secPreviewUrl(): string
{
    return URL::temporarySignedRoute('storefront.preview', now()->addMinutes(60));
}

it('refuses the signed preview to clients, staff without the content permission and tampered links', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), secWebsiteForm(['hero' => ['headline' => 'Secret draft headline']]))->assertSessionHasNoErrors();
    $signed = secPreviewUrl();

    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($clientUser, ['is_owner' => true]);
    $this->actingAs($clientUser)->get($signed)->assertForbidden();

    $this->actingAs(User::factory()->staff(['catalog.*', 'storefront.publish'])->create())->get($signed)->assertForbidden();

    $this->actingAs(User::factory()->staff(['storefront.content.manage'])->create());
    $this->get($signed.'x')->assertForbidden();
    $this->get(str_replace('signature=', 'signature=0', $signed))->assertForbidden();
    $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $signed))->assertForbidden();
    $this->get($signed)->assertOk()->assertDontSee('Secret draft headline'.'x');
});

it('FINDING L1: serves the signed preview with Referrer-Policy no-referrer and no caching', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), secWebsiteForm());

    $response = $this->get(secPreviewUrl())->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private');
    // Security standards §1/§6: pages with a token in the URL send no Referer at all.
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('never shows the draft on the public site, even after the preview rendered first', function (): void {
    app(Settings::class)->set('core.company_name', 'Acme Hosting');
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), secWebsiteForm([
        'hero' => ['headline' => 'Draft only headline'],
        'seo' => ['title' => 'Draft only title', 'description' => 'Draft only description'],
        'legal' => ['terms_url' => 'https://draft-only.example/terms'],
        'header' => ['menu_label' => 'DraftMenu'],
    ]))->assertSessionHasNoErrors();
    app(StorefrontCache::class)->flush();

    $this->get(secPreviewUrl())->assertOk()->assertSee('Draft only headline')->assertSee('draft-only.example');

    foreach (['/', '/store', '/domains/pricing', '/no-such-page'] as $path) {
        $html = $this->get($path)->getContent();
        expect($html)->not->toContain('Draft only')->not->toContain('draft-only.example')->not->toContain('DraftMenu');
    }

    auth()->logout();

    foreach (['/', '/store', '/domains/pricing', '/sitemap.xml'] as $path) {
        $html = $this->get($path)->getContent();
        expect($html)->not->toContain('Draft only')->not->toContain('draft-only.example')->not->toContain('DraftMenu');
    }
});

it('publishes in a transaction, audits the changes and fires StorefrontPublished after commit', function (): void {
    $levels = [];
    $baseline = DB::transactionLevel();
    Event::listen(StorefrontPublished::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });
    $staff = actingAsStaff(['storefront.content.manage', 'storefront.publish']);
    $this->put(route('admin.storefront.website.update'), secWebsiteForm(['legal' => ['terms_url' => 'https://example.com/terms']]));

    $this->post(route('admin.storefront.website.publish'))->assertRedirect();
    $this->post(route('admin.storefront.website.publish'))->assertRedirect();

    expect($levels)->toBe([$baseline]);
    expect(Revision::query()->where('status', Revision::PUBLISHED)->count())->toBe(1);
    $log = ActivityLog::query()->where('event', 'storefront.published')->sole();
    expect($log->user_id)->toBe($staff->id)
        ->and($log->properties['changes'])->toContain('Headline changed', 'Legal page links changed');
});

it('FINDING M1: publishes only the draft the publisher reviewed, not one saved after the confirmation was shown', function (): void {
    $editor = User::factory()->staff(['storefront.content.manage'])->create();
    $publisher = User::factory()->staff(['storefront.content.manage', 'storefront.publish'])->create();

    $this->actingAs($editor)->put(route('admin.storefront.website.update'), secWebsiteForm(['hero' => ['headline' => 'Reviewed headline']]));
    $this->actingAs($publisher)->get(route('admin.storefront.website.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('draft.changes', ['Headline changed']));

    // After the publisher opened the confirmation, the editor (who may not publish) changes the draft.
    $this->actingAs($editor)->put(route('admin.storefront.website.update'), secWebsiteForm([
        'hero' => ['headline' => 'Reviewed headline'],
        'legal' => ['terms_url' => 'https://unreviewed.example/terms'],
    ]));

    $this->actingAs($publisher)->post(route('admin.storefront.website.publish'));

    auth()->logout();
    expect($this->get('/')->getContent())->not->toContain('unreviewed.example');
});

it('FINDING M2: discarding a stale draft never deletes the version that was published meanwhile', function (): void {
    $staff = User::factory()->staff(['storefront.content.manage', 'storefront.publish'])->create();
    $store = app(ContentStore::class);
    $store->saveDraft(secWebsiteForm(['hero' => ['headline' => 'Live headline']]), $staff);
    $stale = $store->draft();

    // Staff A clicks Discard while staff B's Publish commits between discardDraft()'s read and its delete.
    $store->publish($staff);
    $racing = new class(app(\App\Modules\Core\Support\ActivityLogger::class), app(StorefrontCache::class), $stale) extends ContentStore
    {
        public function __construct($activity, $cache, private Revision $stale)
        {
            parent::__construct($activity, $cache);
        }

        public function draft(): ?Revision
        {
            return $this->stale;
        }
    };
    $racing->discardDraft($staff);

    expect(Revision::query()->where('status', Revision::PUBLISHED)->exists())->toBeTrue();
});

it('accepts https legal links only and plain text in every text field', function (string $field, string $value): void {
    actingAsStaff(['storefront.content.manage']);

    $form = secWebsiteForm(['why_us' => [['icon' => 'clock', 'title' => 'Fast', 'text' => 'Quick support']]]);
    data_set($form, $field, $value);

    $this->put(route('admin.storefront.website.update'), $form)->assertSessionHasErrors($field);

    expect(Revision::query()->exists())->toBeFalse();
})->with([
    ['legal.terms_url', 'javascript:alert(1)'],
    ['legal.terms_url', 'JaVaScRiPt://example.com/%0aalert(1)'],
    ['legal.privacy_url', 'data:text/html;base64,PHNjcmlwdD4='],
    ['legal.refund_url', '//evil.example/refund'],
    ['legal.refund_url', 'http://example.com/refund'],
    ['legal.terms_url', 'https://example.com/" onmouseover="alert(1)'],
    ['legal.terms_url', 'vbscript:msgbox(1)'],
    ['hero.eyebrow', '<img src=x onerror=alert(1)>'],
    ['hero.subtext', '< script>alert(1)</script>'],
    ['why_us.0.title', '<svg onload=alert(1)>'],
    ['why_us.0.text', '</p><b>x</b>'],
    ['header.menu_label', '<a href=//x>y</a>'],
    ['seo.title', '</title><script>alert(1)</script>'],
    ['seo.description', '<!--x-->'],
    ['prices.notes.USD', '<i>incl. VAT</i>'],
]);

it('drops unknown and internal keys from a submission', function (): void {
    actingAsStaff(['storefront.content.manage']);

    $this->put(route('admin.storefront.website.update'), [
        ...secWebsiteForm(['why_us' => [['icon' => 'clock', 'title' => 'Fast', 'text' => 'Quick support', 'html' => '<b>x</b>']]]),
        'status' => Revision::PUBLISHED,
        'published_at' => now()->toIso8601String(),
        'hero' => [...secWebsiteForm()['hero'], 'html' => '<script>x</script>'],
        'scripts' => ['<script>x</script>'],
    ])->assertSessionHasNoErrors();

    $draft = Revision::query()->sole();
    expect($draft->status)->toBe(Revision::DRAFT)
        ->and(json_encode($draft->content))->not->toContain('<script')->not->toContain('<b>')->not->toContain('"html"')
        ->and(array_keys($draft->content))->toBe(array_keys(SiteContent::defaults()));
});

it('escapes stored content in the HTML head, meta tags, JSON-LD and the page data', function (): void {
    // Defence in depth: content written around validation (an import, a direct row) must still render inert.
    $payload = '"></title></script><script>alert(1)</script><img src=x onerror=alert(2)>';
    app(Settings::class)->set('core.company_name', 'Evil </script><script>alert(3)</script> Co');
    app(Settings::class)->set('billing.seller.address', '<!-- </script><script>alert(4)</script>');
    Revision::query()->create(['status' => Revision::PUBLISHED, 'published_at' => now(), 'content' => array_replace_recursive(SiteContent::defaults(), [
        'hero' => ['headline' => $payload, 'subtext' => $payload],
        'seo' => ['title' => $payload, 'description' => $payload],
        'why_us' => [['icon' => 'clock', 'title' => $payload, 'text' => $payload]],
    ])]);
    app(StorefrontCache::class)->flush();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(')
        ->not->toContain('<img src=x')
        ->not->toContain('</title></script>')
        ->toContain('application/ld+json')
        ->toContain('</script>');
    expect(substr_count($html, '</script>'))->toBe(substr_count($html, '<script'));
});

it('shows guests only public articles and live announcements on the home page', function (): void {
    KbArticle::factory()->create(['title' => 'Public article', 'is_published' => true, 'is_private' => false, 'views' => 1]);
    KbArticle::factory()->create(['title' => 'Private article', 'is_published' => true, 'is_private' => true, 'views' => 900]);
    KbArticle::factory()->create(['title' => 'Unpublished article', 'is_published' => false, 'is_private' => false, 'views' => 900]);
    Announcement::factory()->create(['title' => 'Live announcement', 'is_published' => true, 'published_at' => now()->subDay()]);
    Announcement::factory()->create(['title' => 'Scheduled announcement', 'is_published' => true, 'published_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Draft announcement', 'is_published' => false, 'published_at' => now()->subDay()]);
    Announcement::factory()->create(['title' => 'Undated announcement', 'is_published' => true, 'published_at' => null]);

    // A signed-in visit first, so a shared cache entry would leak into the guest page.
    actingAsStaff();
    $this->get('/')->assertOk();
    auth()->logout();

    $html = $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('news.articles', fn ($articles) => collect($articles)->pluck('title')->all() === ['Public article'])
        ->where('news.announcements', fn ($items) => collect($items)->pluck('title')->all() === ['Live announcement']))
        ->getContent();

    expect($html)->not->toContain('Private article')->not->toContain('Unpublished article')
        ->not->toContain('Scheduled announcement')->not->toContain('Draft announcement')->not->toContain('Undated announcement');
});

it('FINDING L2: applies the admin-area staff checks (verified email, forced password change, required 2FA) to the preview', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), secWebsiteForm());
    $signed = secPreviewUrl();

    $forced = User::factory()->staff(['storefront.content.manage'])->create();
    $forced->forceFill(['must_change_password' => true])->save();
    $this->actingAs($forced->fresh());
    $this->get(route('admin.storefront.website.edit'))->assertRedirect();
    $this->get($signed)->assertRedirect();

    $this->actingAs(User::factory()->staff(['storefront.content.manage'])->unverified()->create());
    $this->get(route('admin.storefront.website.edit'))->assertRedirect();
    $this->get($signed)->assertRedirect();
});
