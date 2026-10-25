<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\TaxNotePdf;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders a new note's original PDF after it commits, so the note email's send only reads the file. A failure is
 * reported and left to the send, which renders on a cache miss.
 */
class RenderTaxNotePdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $taxNoteId, public ?string $locale = null)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function handle(TaxNotePdf $pdf): void
    {
        $note = TaxNote::query()->find($this->taxNoteId);

        if ($note === null) {
            return;
        }

        try {
            $pdf->ensure($note, $this->locale);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
