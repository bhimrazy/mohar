<?php

use App\Events\Storefront\StorefrontPublished;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Services\ContentStore;
use App\Modules\Storefront\Support\SiteContent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
});

/**
 * A full, valid form submission with the given overrides.
 *
 * @return array<string, mixed>
 */
function websiteForm(array $overrides = []): array
{
    return array_replace_recursive(SiteContent::defaults(), ['hero' => ['headline' => 'Reliable hosting for your website']], $overrides);
}

it('requires the website permission to view or edit the draft', function (): void {
    actingAsStaff(['catalog.*']);

    $this->get(route('admin.storefront.website.edit'))->assertForbidden();
    $this->put(route('admin.storefront.website.update'), websiteForm())->assertForbidden();

    actingAsStaff(['storefront.content.manage']);

    $this->get(route('admin.storefront.website.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/storefront/website')
        ->where('needs_text', true)
        ->where('draft', null)
        ->where('can_publish', false));
});

it('keeps the live page until the draft is published, then lists the changes and records the publish', function (): void {
    Event::fake([StorefrontPublished::class]);
    $staff = actingAsStaff(['storefront.content.manage', 'storefront.publish']);

    $this->put(route('admin.storefront.website.update'), websiteForm(['seo' => ['title' => 'Acme | Hosting']]))->assertRedirect(route('admin.storefront.website.edit'));

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.headline', fn (string $headline) => str_starts_with($headline, 'Hosting and domains from ')));
    $this->get(route('admin.storefront.website.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('draft.changes', ['Headline changed', 'Page title changed']));
    expect(ActivityLog::query()->where('event', 'storefront.draft_saved')->sole()->properties['sections'])->toBe(['hero', 'seo']);

    // Publishing is sensitive: without a recently confirmed password nothing goes live.
    $this->post(route('admin.storefront.website.publish'), ['reviewed' => app(ContentStore::class)->reviewToken(app(ContentStore::class)->draft())])
        ->assertSessionHasErrors('password_confirmation');
    expect(Revision::query()->where('status', Revision::PUBLISHED)->exists())->toBeFalse();

    confirmPassword();
    $this->post(route('admin.storefront.website.publish'), ['reviewed' => app(ContentStore::class)->reviewToken(app(ContentStore::class)->draft())])->assertRedirect();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.headline', 'Reliable hosting for your website'));
    expect(Revision::query()->where('status', Revision::DRAFT)->exists())->toBeFalse();
    Event::assertDispatched(StorefrontPublished::class, fn (StorefrontPublished $event): bool => $event->publishedBy === $staff->id && $event->changedSections === ['hero', 'seo']);
    expect(ActivityLog::query()->where('event', 'storefront.published')->value('properties'))->toMatchArray(['changes' => ['Headline changed', 'Page title changed']]);
});

it('lets only staff with the publish permission make the draft live', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), websiteForm());

    confirmPassword();
    $this->post(route('admin.storefront.website.publish'), ['reviewed' => str_repeat('a', 64)])->assertForbidden();
    expect(Revision::query()->where('status', Revision::PUBLISHED)->exists())->toBeFalse();
});

it('accepts plain text only, visible groups, known currencies and https legal links', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $hidden = ProductGroup::factory()->hidden()->create();

    $this->put(route('admin.storefront.website.update'), websiteForm([
        'hero' => ['headline' => '<script>alert(1)</script>'],
        'featured' => ['group_ids' => [$hidden->id]],
        'legal' => ['terms_url' => 'http://example.com/terms'],
        'prices' => ['tax_inclusive' => ['EUR']],
    ]))->assertSessionHasErrors(['hero.headline', 'featured.group_ids.0', 'legal.terms_url', 'prices.tax_inclusive.0']);

    expect(Revision::query()->exists())->toBeFalse();
});

it('previews the draft only at a signed, unexpired link for staff who may edit it', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), websiteForm(['hero' => ['headline' => 'Draft headline']]));
    $signed = URL::temporarySignedRoute('admin.storefront.website.preview', now()->addHour());

    $this->get($signed)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
        ->where('preview', true)
        ->where('hero.headline', 'Draft headline')
        ->where('seo.robots', 'noindex, nofollow'));

    $this->get(route('admin.storefront.website.preview'))->assertForbidden();
    $this->travel(61)->minutes();
    $this->get($signed)->assertForbidden();

    auth()->logout();
    $this->travelBack();
    $this->get($signed)->assertRedirect(route('login'));
});

it('discards the draft and leaves the published version alone', function (): void {
    actingAsStaff(['storefront.content.manage']);
    $this->put(route('admin.storefront.website.update'), websiteForm());

    $this->delete(route('admin.storefront.website.discard'))->assertRedirect();

    expect(Revision::query()->count())->toBe(0);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.headline', fn (string $headline) => str_starts_with($headline, 'Hosting and domains from ')));
});
