<?php

use App\Models\User;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Support\Dashboard\Dashboard;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\RecordingPermissionRegistry;

require_once __DIR__.'/../Billing/helpers.php';

/*
 * Review probes for #577 (AdminPermissionSweepTest and the Billing Form Requests).
 */

function sweepGapRecorder(): RecordingPermissionRegistry
{
    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);

    return $recorder;
}

test('the sweep criterion (403 and any permission looked up) accepts a 403 that no permission caused', function () {
    // A route whose only guard is an unrelated abort, after a soft permission lookup (a UI flag, a filter).
    Route::middleware(['web', 'auth'])->get('admin/zz-sweep-probe', function (Request $request) {
        $canSeeClients = $request->user()->hasPermission('core.clients.view');
        abort(403, 'Not in this state.');
    })->name('admin.zz-sweep-probe');

    $recorder = sweepGapRecorder();
    $this->actingAs(User::factory()->staff([])->create());
    $status = $this->getJson('/admin/zz-sweep-probe')->status();

    // AdminPermissionSweepTest counts this as "refused": status 403 and $checked !== [].
    expect($status)->toBe(403)->and($recorder->checked)->toBe(['core.clients.view']);
});

test('the sweep reaches only the default branch of the ticket bulk action', function () {
    $recorder = sweepGapRecorder();
    $this->actingAs(User::factory()->staff([])->create())->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);

    // What the sweep sends (no body for admin.support.tickets.bulk): only support.tickets.manage is looked up.
    $this->postJson(route('admin.support.tickets.bulk'), [])->assertForbidden();
    expect($recorder->checked)->not->toContain('support.tickets.delete')->not->toContain('support.inbound.manage');

    $recorder->checked = [];
    $this->postJson(route('admin.support.tickets.bulk'), ['action' => 'delete', 'ids' => [1]])->assertForbidden();
    expect($recorder->checked)->toContain('support.tickets.delete');
});

test('an empty role sees no admin dashboard widget, to-do or health check', function () {
    $dashboard = app(Dashboard::class);
    $unguarded = [];

    foreach (['widgets', 'todos', 'health'] as $kind) {
        foreach ((fn (): array => $this->{$kind}['admin'] ?? [])->call($dashboard) as $entry) {
            if ($entry['permission'] === null) {
                $unguarded[] = "{$kind}:{$entry['key']}";
            }
        }
    }

    $nobody = User::factory()->staff([])->create();

    expect($unguarded)->toBe([])
        ->and($dashboard->resolveWidgets('admin', $nobody))->toBe([])
        ->and($dashboard->resolveTodos('admin', $nobody))->toBe([])
        ->and($dashboard->resolveHealth('admin', $nobody))->toBe([]);
});

test('resolving a settled refund: 403 without the refunds permission whatever the body, 422 or 404 with it', function () {
    $settled = Transaction::factory()->create(['type' => TransactionType::Refund, 'status' => TransactionStatus::Succeeded]);

    actingAsStaff(['billing.transactions.view']);
    confirmPassword();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), [])->assertForbidden();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), ['outcome' => 'not_refunded'])->assertForbidden();

    actingAsStaff(['billing.transactions.view', 'billing.refunds.manage']);
    confirmPassword();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), [])->assertUnprocessable();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), ['outcome' => 'not_refunded'])->assertNotFound();
});

test('client billing Form Requests refuse an account user without the billing area before validating', function () {
    app(Settings::class)->set('billing.quotes.require_tos', true);
    $client = Client::factory()->create();
    $quote = Quote::factory()->delivered()->create(['client_id' => $client->id]);
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['support'])]);
    $this->actingAs($user);

    $this->post(route('client.invoices.batches.store'), ['invoice_ids' => 'not-an-array'])->assertForbidden();
    $this->post(route('client.quotes.accept', $quote->id), [])->assertForbidden();
});

test('a client cannot accept another client\'s quote, and the terms check reveals nothing about it', function () {
    app(Settings::class)->set('billing.quotes.require_tos', true);
    [, $client] = actingAsClient();
    $own = Quote::factory()->delivered()->create(['client_id' => $client->id]);
    $foreign = Quote::factory()->delivered()->create();

    // Without the terms both answer the same validation error; with them, the foreign quote is 404.
    $this->post(route('client.quotes.accept', $own->id), [])->assertSessionHasErrors('accept_tos');
    $this->post(route('client.quotes.accept', $foreign->id), [])->assertSessionHasErrors('accept_tos');
    $this->post(route('client.quotes.accept', $foreign->id), ['accept_tos' => '1'])->assertNotFound();
    $this->post(route('client.quotes.accept', $own->id), ['accept_tos' => '1'])->assertRedirect();
    expect($foreign->fresh()->stage->value)->toBe('delivered');
});

test('a client batch cannot include another client\'s invoice', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $foreign = issueInvoice(Client::factory()->create(), [1_000]);

    $this->post(route('client.invoices.batches.store'), ['invoice_ids' => [$foreign->id]]);

    expect(PaymentBatch::query()->where('client_id', $client->id)->get()
        ->flatMap(fn ($batch) => $batch->invoices()->pluck('invoices.id'))->all())->not->toContain($foreign->id);
});
