<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Events\NetworkIncidentResolved;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\CannedReply;
use App\Modules\Support\Models\CannedReplyCategory;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\SpamRule;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('departments are created with staff and a lowercase unique email', function () {
    actingAsStaff(['support.departments.manage']);
    $agent = User::factory()->staff()->create();

    $this->get(route('admin.support.departments.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/support/departments/form'));
    $this->post(route('admin.support.departments.store'), [
        'name' => 'Technical Support',
        'email' => 'Tech@Example.com',
        'clients_only' => true,
        'send_autoresponse' => true,
        'autoresponse_message' => 'Thanks, we will reply soon.',
        'staff' => [$agent->id],
    ])->assertRedirect(route('admin.support.departments.index'));

    $department = Department::query()->sole();
    expect($department)->email->toBe('tech@example.com')->slug->toBe('technical-support')->clients_only->toBeTrue()
        ->and($department->staff()->pluck('users.id')->all())->toBe([$agent->id]);

    $this->post(route('admin.support.departments.store'), ['name' => 'Other', 'email' => 'TECH@example.com'])->assertSessionHasErrors('email');
});

test('departments with tickets cannot be deleted', function () {
    actingAsStaff(['support.departments.manage']);
    $department = Department::factory()->create();
    clientTicket(Client::factory()->create(), $department);
    $empty = Department::factory()->create();

    $this->delete(route('admin.support.departments.destroy', $department))->assertSessionHasErrors('department');
    $this->delete(route('admin.support.departments.destroy', $empty))->assertRedirect();

    expect(Department::query()->pluck('id')->all())->toBe([$department->id]);
});

test('content management requires the matching permission', function (string $route) {
    actingAsStaff(['support.tickets.view']);

    $this->get(route($route))->assertForbidden();
})->with([
    'admin.support.departments.index',
    'admin.support.canned-replies.index',
    'admin.support.kb.articles.index',
    'admin.support.announcements.index',
    'admin.support.network.index',
    'admin.support.inbound.index',
]);

test('canned replies are managed with categories and searchable', function () {
    actingAsStaff(['support.canned.manage']);
    $this->post(route('admin.support.canned-reply-categories.store'), ['name' => 'Billing'])->assertRedirect();
    $category = CannedReplyCategory::query()->sole();

    $this->post(route('admin.support.canned-replies.store'), ['name' => 'Refund policy', 'category_id' => $category->id, 'body' => 'Hi {{ requester.first_name }}, refunds take 5 days.'])
        ->assertRedirect(route('admin.support.canned-replies.index'));
    $reply = CannedReply::query()->sole();

    $this->get(route('admin.support.canned-replies.index', ['search' => 'REFUNDS']))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/canned-replies/index')->has('replies', 1)->where('replies.0.category', 'Billing'));
    $this->get(route('admin.support.canned-replies.index', ['search' => 'nothing']))
        ->assertInertia(fn (Assert $page) => $page->has('replies', 0));

    $this->put(route('admin.support.canned-replies.update', $reply), ['name' => 'Refunds', 'body' => 'Updated'])->assertRedirect();
    expect($reply->fresh()->name)->toBe('Refunds');

    $this->delete(route('admin.support.canned-replies.destroy', $reply))->assertRedirect();
    expect(CannedReply::query()->count())->toBe(0);
});

test('knowledge base articles and categories are managed by editors', function () {
    actingAsStaff(['support.kb.manage']);

    $this->post(route('admin.support.kb.categories.store'), ['name' => 'Getting started'])->assertRedirect();
    $parent = KbCategory::query()->sole();
    $this->post(route('admin.support.kb.categories.store'), ['name' => 'Email', 'parent_id' => $parent->id])->assertRedirect();
    $child = KbCategory::query()->where('slug', 'email')->sole();
    $this->post(route('admin.support.kb.categories.store'), ['name' => 'Too deep', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');

    $this->post(route('admin.support.kb.articles.store'), [
        'title' => 'Connect via SSH',
        'body' => '## Steps',
        'is_published' => true,
        'tags' => 'ssh, terminal',
        'categories' => [$child->id],
    ])->assertRedirect();

    $article = KbArticle::query()->sole();
    expect($article)->slug->toBe('connect-via-ssh')->tags->toBe(['ssh', 'terminal'])
        ->and($article->categories()->pluck('support_kb_categories.id')->all())->toBe([$child->id]);

    $this->get(route('admin.support.kb.articles.edit', $article))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/kb/form')->where('article.body_html', fn (string $html) => str_contains($html, '<h2>Steps</h2>')));

    $this->get(route('admin.support.kb.articles.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/kb/index')->has('articles.data', 1)->has('categories', 2));

    $this->delete(route('admin.support.kb.articles.destroy', $article))->assertRedirect();
    expect(KbArticle::query()->count())->toBe(0);
});

test('announcements can be drafted, scheduled and published', function () {
    actingAsStaff(['support.announcements.manage']);

    $this->post(route('admin.support.announcements.store'), ['title' => 'Planned maintenance', 'body' => 'Sunday 2am', 'is_published' => true, 'published_at' => now()->addDay()->toDateTimeString()])
        ->assertRedirect(route('admin.support.announcements.index'));

    $this->get(route('admin.support.announcements.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/announcements/index')->where('announcements.data.0.status', 'scheduled'));

    $announcement = Announcement::query()->sole();
    $this->put(route('admin.support.announcements.update', $announcement), ['title' => 'Planned maintenance', 'body' => 'Sunday', 'is_published' => true, 'published_at' => null])->assertRedirect();
    expect($announcement->fresh()->isLive())->toBeTrue();
});

test('network incidents are published, updated and resolved', function () {
    Event::fake([NetworkIncidentResolved::class]);
    actingAsStaff(['support.network.manage']);

    $this->post(route('admin.support.network.store'), [
        'title' => 'Router failure',
        'type' => 'server',
        'affected' => 'web01',
        'priority' => 'high',
        'status' => 'investigating',
        'description' => 'Investigating packet loss.',
        'starts_at' => now()->toDateTimeString(),
    ])->assertRedirect();
    $incident = NetworkIncident::query()->sole();

    $this->post(route('admin.support.network.updates.store', $incident), ['body' => 'Cause identified', 'status' => 'identified'])->assertRedirect();
    expect($incident->fresh()->status)->toBe(IncidentStatus::Identified)
        ->and($incident->updates()->count())->toBe(1);

    $this->post(route('admin.support.network.resolve', $incident))->assertRedirect();
    expect($incident->fresh())->status->toBe(IncidentStatus::Resolved)->ends_at->not->toBeNull();
    Event::assertDispatched(NetworkIncidentResolved::class);

    $this->get(route('admin.support.network.edit', $incident))->assertInertia(fn (Assert $page) => $page->component('admin/support/network/form')->has('updates', 1));
});

test('spam rules are managed from the inbound log', function () {
    actingAsStaff(['support.inbound.manage']);

    $this->post(route('admin.support.spam-rules.store'), ['field' => 'sender', 'match' => 'equals', 'value' => 'spam@x.test'])->assertRedirect();
    $this->post(route('admin.support.spam-rules.store'), ['field' => 'header', 'match' => 'equals', 'value' => 'x'])->assertSessionHasErrors('field');

    $this->get(route('admin.support.inbound.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/inbound/index')->has('rules', 1)->has('endpoint'));

    $this->delete(route('admin.support.spam-rules.destroy', SpamRule::query()->sole()))->assertRedirect();
    expect(SpamRule::query()->count())->toBe(0);
});
