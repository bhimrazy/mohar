<?php

namespace App\Modules\Billing\Import;

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
use Illuminate\Database\Eloquent\Builder;
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
 * Each entry carries the idempotency key `import:run:<run>:client:<client>` (unique), so a run posts at most once per
 * client; its undo carries `import:undo:run:<run>:client:<client>`. revert() (undo, §5.7) takes back exactly what one
 * run posted while the balance still covers it.
 *
 * Expand release (#263, architecture.md "expand, then contract"): every entry also carries the pre-#263 pair, gateway
 * `import` and external_ref `run:…` (`undo:run:…`), and either is matched, so a previous-release worker running
 * beside this one during a blue-green deploy sees what this release posted and the other way round. The contract step
 * (#284) stops writing and matching the pair.
 *
 * Both writes open their own transaction and lock the client before reading its ledger (ADR 0021 §3, import: run,
 * migration record, then client).
 */
class OpeningBalanceImporter implements RevertsImport
{
    /** The pre-#263 gateway value, still written beside the key until #284 (reserved in GatewayManager). */
    private const LEGACY_GATEWAY = 'import';

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

            if ($difference === 0 || $this->posted(clone $imports, $reference)->exists()) {
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
                    staffId: $context->actorId, gateway: self::LEGACY_GATEWAY, externalRef: $reference, idempotencyKey: 'import:'.$reference);
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
            $posted = $client === null ? null : $this->posted(CreditLedgerEntry::query()->where('client_id', $client->id), $reference)->first();

            if ($client === null || $posted === null || $this->posted(CreditLedgerEntry::query(), 'undo:'.$reference)->exists()) {
                return new ImportOutcome($client?->id, ImportAction::Unchanged);
            }

            $back = Money::of(-$posted->amount, $posted->currency);

            if ($back->isNegative() && $this->ledger->balance($client->id, $posted->currency)->add($back)->isNegative()) {
                return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_used', __('The opening balance was kept: the client has already used part of it.'))]);
            }

            if (! $context->dryRun) {
                $this->ledger->post($client->id, $back, CreditReason::Import, description: __('Opening balance reversed: the import was undone'),
                    staffId: $context->actorId, gateway: self::LEGACY_GATEWAY, externalRef: 'undo:'.$reference, idempotencyKey: 'import:undo:'.$reference);
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

    /**
     * The entries carrying $reference: by idempotency key, or by the pre-#263 pair (both served by a unique index; the
     * pair goes in #284).
     *
     * @param  Builder<CreditLedgerEntry>  $query
     * @return Builder<CreditLedgerEntry>
     */
    private function posted(Builder $query, string $reference): Builder
    {
        return $query->where(fn (Builder $entry) => $entry->where('idempotency_key', 'import:'.$reference)
            ->orWhere(fn (Builder $legacy) => $legacy->where('gateway', self::LEGACY_GATEWAY)->where('external_ref', $reference)));
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
