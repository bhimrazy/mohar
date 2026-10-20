<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Re-review probes for #95 (import step 3b-1). start() checks the reviewed digest under the server lock, but each
 * queued chunk (ImportServerAccounts::handle()) plans the rows again from live data and creates what it finds then,
 * not the reviewed rows. Whatever changes between the confirmation and the chunk is imported unreviewed. The price
 * and racing-PUT cases are [R2-3] and [R2-4] in Security/ServerImportApplyRound2SecurityTest.php; this one shows the
 * chunk still falls back to the email match when a reviewed row has no client in `selections`.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group = ServerGroup::factory()->create();
    $this->group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->mallory = Client::factory()->create(['email' => 'mallory@example.com', 'currency' => 'USD']);

    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
});

/**
 * Review, confirm with the digest, and leave the chunk on the (faked) queue.
 */
function rrConfirmQueued(Server $server): ServerAccountImport
{
    Queue::fake();
    $digest = test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
    test()->post(route('admin.services.servers.import.apply', $server), ['digest' => $digest])->assertSessionHasNoErrors()->assertRedirect();
    Queue::assertPushed(ImportReviewedAccounts::class);

    return ServerAccountImport::query()->sole();
}

function rrRunChunk(ServerAccountImport $import): void
{
    (new ImportReviewedAccounts($import->id, auth()->id()))->handle(app(ImportServerAccounts::class));
}

test('[RR-1] a reviewed row whose pinned client is missing is not matched again by email in the chunk', function () {
    $import = rrConfirmQueued($this->server);
    expect($import->reviewed['rows'][0]['client_id'])->toBe($this->anna->id);

    // The pin review() wrote into `selections` is lost, as a concurrent PUT …/selections (read before the review GET
    // wrote, saved after it) leaves it: both write the whole JSON column. start() still passes, because today's email
    // match gives the same client. Then the client email changes while the chunk is queued.
    $selections = $import->selections;
    unset($selections['alpha']['client_id']);
    $import->update(['selections' => $selections]);
    $this->anna->update(['email' => 'anna@elsewhere.example']);
    $this->mallory->update(['email' => 'anna@alpha-books.com']);
    rrRunChunk($import->fresh());

    // Expected: alpha goes to Anna (the reviewed client_id) or is refused. At b277a24f the chunk falls back to the
    // email match (AccountImportPlan::rows(): `$selections[...]['client_id'] ?? $row['client_id']`) and gives it to Mallory.
    expect(Service::query()->where('client_id', $this->mallory->id)->exists())->toBeFalse();
});
