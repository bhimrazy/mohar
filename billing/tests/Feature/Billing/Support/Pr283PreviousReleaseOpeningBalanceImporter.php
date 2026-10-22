<?php

namespace Tests\Feature\Billing\Support;

// Review probe fixture for PR #283: the opening-balance importer exactly as origin/develop (the previous release)
// ships it, renamed only. It stands in for an old-release worker during the blue-green overlap.

use App\Contracts\Migration\RevertsImport;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Enums\ImportSeverity;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * An imported client's opening credit balance (`credit_balance`, client-import spec §6.12). Attributes: `amount`
 * (minor units, zero or more) and `currency` (the currency the file gives it in; null means the client's); link
 * `client_id`. `$existingTargetId` is null when the same run has just created the client, and the client's id when
 * the run updates a client that already existed.
 *
 * The balance is posted automatically in two cases only (CTO decision, 2026-10-22):
 *  - the client was created by this run: the file's amount, "Opening balance from <source>";
 *  - a later run, when the client's credit has not moved since its last import entry: the difference to the file's
 *    amount, "Opening balance from <source>, changed by a later import".
 * In every other case (an existing client with no earlier import, or credit that moved since the last import) the
 * balance is kept, nothing is posted, and the row says "Existing credit kept; post the opening balance manually if it
 * is still owed." History is never edited.
 *
 * Each entry carries the reference `import` / `run:<run>:client:<client>` (unique), so a run posts at most once per
 * client. revert() (undo, §5.7) takes back exactly what one run posted while the balance still covers it.
 *
 * Both writes open their own transaction and lock the client before reading its ledger (ADR 0021 §3, import: run,
 * migration record, then client).
 */
class Pr283PreviousReleaseOpeningBalanceImporter implements RevertsImport
{
    private const GATEWAY = 'import';

    public function __construct(private CreditLedgerService $ledger) {}

    public function key(): string
    {
        return 'credit_balance';
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'integer', 'min:0'], 'currency' => ['nullable', 'string', 'size:3']];
    }

    public function validate(ImportRecord $record, ImportContext $context): array
    {
        return [];
    }

    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
    {
        return DB::transaction(function () use ($record, $context, $existingTargetId): ImportOutcome {
            $client = $this->client((int) ($record->links['client_id'] ?? 0), $context);

            if ($client === null) {
                return new ImportOutcome(null, ImportAction::Skipped, [$this->issue(ImportSeverity::Error, 'credit_no_client', __('The opening balance was not imported: the client was not found.'))]);
            }

            if (strtoupper((string) ($record->attributes['currency'] ?? $client->currency)) !== $client->currency) {
                return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Error, 'credit_currency', __('The opening balance is not in the client\'s currency (:currency), so it cannot be imported.', ['currency' => $client->currency]))]);
            }

            $imports = CreditLedgerEntry::query()->where('client_id', $client->id)->where('currency', $client->currency)->where('reason', CreditReason::Import);
            $imported = (int) (clone $imports)->sum('amount');
            $difference = (int) $record->attributes['amount'] - $imported;
            $reference = $this->reference($context->runId, $client->id);

            if ($difference === 0 || (clone $imports)->where('gateway', self::GATEWAY)->where('external_ref', $reference)->exists()) {
                return new ImportOutcome($client->id, ImportAction::Unchanged);
            }

            $lastImport = (int) (clone $imports)->max('id');
            $moved = CreditLedgerEntry::query()->where('client_id', $client->id)->where('id', '>', $lastImport)->exists();
            $created = $existingTargetId === null && $lastImport === 0;

            if ($moved || ($lastImport === 0 && ! $created)) {
                return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_kept', __('Existing credit kept; post the opening balance manually if it is still owed.'))]);
            }

            if (! $context->dryRun) {
                $source = (string) ($context->options['source_name'] ?? '');
                $this->ledger->post($client->id, Money::of($difference, $client->currency), CreditReason::Import,
                    description: $created ? __('Opening balance from :source', ['source' => $source]) : __('Opening balance from :source, changed by a later import', ['source' => $source]),
                    staffId: $context->actorId, gateway: self::GATEWAY, externalRef: $reference);
            }

            return new ImportOutcome($client->id, $created ? ImportAction::Created : ImportAction::Updated, changes: ['credit_balance'], before: ['credit_balance' => $imported]);
        });
    }

    /**
     * Takes back the entry run $context->runId posted for the client ($applied->targetId).
     */
    public function revert(ImportRecord $record, ImportOutcome $applied, ImportContext $context): ImportOutcome
    {
        return DB::transaction(function () use ($applied, $context): ImportOutcome {
            $client = $applied->targetId === null ? null : $this->client($applied->targetId, $context);
            $reference = $client === null ? '' : $this->reference($context->runId, $client->id);
            $posted = $client === null ? null : CreditLedgerEntry::query()->where('client_id', $client->id)->where('gateway', self::GATEWAY)->where('external_ref', $reference)->first();

            if ($client === null || $posted === null || CreditLedgerEntry::query()->where('gateway', self::GATEWAY)->where('external_ref', 'undo:'.$reference)->exists()) {
                return new ImportOutcome($client?->id, ImportAction::Unchanged);
            }

            $back = Money::of(-$posted->amount, $posted->currency);

            if ($back->isNegative() && $this->ledger->balance($client->id, $posted->currency)->add($back)->isNegative()) {
                return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_used', __('The opening balance was kept: the client has already used part of it.'))]);
            }

            if (! $context->dryRun) {
                $this->ledger->post($client->id, $back, CreditReason::Import, description: __('Opening balance reversed: the import was undone'),
                    staffId: $context->actorId, gateway: self::GATEWAY, externalRef: 'undo:'.$reference);
            }

            return new ImportOutcome($client->id, ImportAction::Reverted, changes: ['credit_balance']);
        });
    }

    public function finalize(ImportContext $context): array
    {
        return [];
    }

    public function cutover(ImportContext $context): int
    {
        return 0;
    }

    /**
     * The client, locked for the rest of the transaction unless this is a dry run (which reads without locks, ADR 0019).
     */
    private function client(int $clientId, ImportContext $context): ?Client
    {
        return $context->dryRun ? Client::query()->find($clientId) : Client::query()->whereKey($clientId)->lockForUpdate()->first();
    }

    private function reference(int $runId, int $clientId): string
    {
        return "run:{$runId}:client:{$clientId}";
    }

    private function issue(ImportSeverity $severity, string $code, string $message): ImportIssue
    {
        return new ImportIssue($severity, $code, $message);
    }
}
