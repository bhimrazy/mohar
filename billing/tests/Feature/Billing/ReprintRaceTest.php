<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Concurrent downloads of one note (vat-register §9): each takes its own print number under the row lock, so the count
| rises by exactly the number of downloads and no two prints share a number. Needs committed sessions (PostgreSQL).
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

test('three concurrent reprints of one note take prints 2, 3 and 4, each once', function () {
    $this->useSeededDatabase('reprint_race', function (): void {
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR',
            'seller.legal_name' => 'Himal Hosting Pvt Ltd', 'seller.pan' => '609876543', 'seller.vat_number' => '609876543']);
        useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'reprint-race@example.test']);
        $invoice = issueInvoice($client, [1_000_000]);
        $issuer = app(TaxNoteIssuer::class);
        $request = NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Race.', settlement: 'credit');
        $preview = $issuer->preview($request);
        $note = $issuer->issue($request, $preview->version, $preview->digest);
        // The original was handed out already: every racer renders a copy.
        DB::table('tax_notes')->where('id', $note->id)->update(['print_count' => 1]);
    });
    $note = TaxNote::query()->whereHas('invoice', fn ($q) => $q->whereHas('client', fn ($c) => $c->where('email', 'reprint-race@example.test')))->sole();
    $database = DB::connection()->getDatabaseName();
    $start = (string) ((int) (microtime(true) * 1000) + 3_000);

    $results = runRaceWorkers([
        'one' => ['print', $database, (string) $note->id, $start],
        'two' => ['print', $database, (string) $note->id, $start],
        'three' => ['print', $database, (string) $note->id, $start],
    ], 90.0, __DIR__.'/Support/print-worker.php');

    $prints = ActivityLog::query()->where('event', 'billing.tax_note.printed')->where('subject_id', $note->id)->get()->map(fn ($log) => $log->properties['print_count'])->sort()->values()->all();

    expect(collect($results)->pluck('outcome')->unique()->all())->toBe(['printed'])
        ->and($note->fresh()->print_count)->toBe(4)
        ->and($prints)->toBe([2, 3, 4]);

    DB::table('activity_log')->delete();
});
