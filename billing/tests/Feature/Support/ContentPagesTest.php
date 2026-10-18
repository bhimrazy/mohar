<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Models\NetworkIncident;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('the knowledge base lists visible categories and popular articles', function () {
    $visible = KbCategory::factory()->create(['name' => 'Email']);
    KbCategory::factory()->create(['is_hidden' => true]);
    $article = KbArticle::factory()->create(['title' => 'Set up Outlook', 'views' => 50]);
    $article->categories()->attach($visible);
    KbArticle::factory()->draft()->create();

    $this->get(route('support.kb.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/support/kb/index')
            ->has('categories', 1)
            ->where('categories.0.name', 'Email')
            ->where('categories.0.articles_count', 1)
            ->has('popular', 1));

    $this->get(route('support.kb.category', $visible->slug))
        ->assertInertia(fn (Assert $page) => $page->component('public/support/kb/category')->has('articles', 1));
});

test('knowledge base search matches title, body and tags case-insensitively', function () {
    KbArticle::factory()->create(['title' => 'Configure DNS records', 'body' => 'Use the zone editor.']);
    KbArticle::factory()->create(['title' => 'Backups', 'body' => 'Nightly', 'tags' => ['restore']]);
    KbArticle::factory()->private()->create(['title' => 'DNS internals']);

    $this->get(route('support.kb.index', ['q' => 'dns']))
        ->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Configure DNS records'));
    $this->get(route('support.kb.index', ['q' => 'RESTORE']))
        ->assertInertia(fn (Assert $page) => $page->has('results', 1));
});

test('private articles send guests to login and show to signed-in clients', function () {
    $article = KbArticle::factory()->private()->create();

    $this->get(route('support.kb.article', $article->slug))->assertRedirect(route('login'));

    actingAsClient();
    $this->get(route('support.kb.article', $article->slug))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/support/kb/article')->where('article.title', $article->title));
});

test('drafts and hidden categories are not public', function () {
    $draft = KbArticle::factory()->draft()->create();
    $hidden = KbCategory::factory()->create(['is_hidden' => true]);

    $this->get(route('support.kb.article', $draft->slug))->assertNotFound();
    $this->get(route('support.kb.category', $hidden->slug))->assertNotFound();
});

test('article views count once per session and helpful votes once', function () {
    $article = KbArticle::factory()->create(['body' => "# Title\n\n<script>alert(1)</script>Text"]);

    $this->get(route('support.kb.article', $article->slug))
        ->assertInertia(fn (Assert $page) => $page->where('article.body_html', fn (string $html) => ! str_contains($html, '<script')));
    $this->get(route('support.kb.article', $article->slug));
    expect($article->fresh()->views)->toBe(1);

    $this->post(route('support.kb.vote', $article->slug), ['helpful' => 'yes'])->assertRedirect();
    $this->post(route('support.kb.vote', $article->slug), ['helpful' => 'no'])->assertRedirect();
    expect($article->fresh())->votes_up->toBe(1)->votes_down->toBe(0);

    $this->post(route('support.kb.vote', $article->slug), ['helpful' => 'maybe'])->assertSessionHasErrors('helpful');
});

test('scheduled announcements stay hidden until published_at', function () {
    $live = Announcement::factory()->create(['title' => 'Maintenance done']);
    $scheduled = Announcement::factory()->scheduled()->create(['title' => 'New datacenter']);
    Announcement::factory()->create(['is_published' => false]);

    $this->get(route('support.announcements.index'))
        ->assertInertia(fn (Assert $page) => $page->component('public/support/announcements/index')
            ->has('announcements.data', 1)
            ->where('announcements.data.0.title', 'Maintenance done'));
    $this->get(route('support.announcements.show', $scheduled->slug))->assertNotFound();
    $this->get(route('support.announcements.show', $live->slug))->assertOk();

    $this->travelTo($scheduled->published_at->addMinute());
    $this->get(route('support.announcements.show', $scheduled->slug))->assertOk();
    $this->get(route('support.announcements.index'))->assertInertia(fn (Assert $page) => $page->has('announcements.data', 2));
});

test('the announcements RSS feed lists live items with escaped content', function () {
    Announcement::factory()->create(['title' => 'Tom & Jerry <b>', 'body' => 'Hello']);
    Announcement::factory()->scheduled()->create(['title' => 'Future']);

    $this->get(route('support.announcements.feed'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
        ->assertSee('Tom &amp; Jerry &lt;b&gt;', false)
        ->assertDontSee('Future');
});

test('network status lists open incidents first and resolved ones from the last 30 days', function () {
    $open = NetworkIncident::factory()->create(['title' => 'Packet loss']);
    $recent = NetworkIncident::factory()->resolved()->create(['title' => 'Disk failure']);
    NetworkIncident::factory()->resolved()->create(['ends_at' => now()->subDays(40)]);

    $this->get(route('support.network.index'))->assertRedirect(route('login'));

    actingAsClient();
    $this->get(route('support.network.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/support/network')
            ->has('open', 1)->where('open.0.title', $open->title)
            ->has('resolved', 1)->where('resolved.0.title', $recent->title));
});

test('network status can be public by setting', function () {
    app(Settings::class)->set('support.network_status_requires_login', false);

    $this->get(route('support.network.index'))->assertOk();
});
