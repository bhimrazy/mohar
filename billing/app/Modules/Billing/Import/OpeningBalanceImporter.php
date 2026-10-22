<?php

namespace App\Modules\Billing\Import;

use App\Contracts\Migration\ReversibleImporter;
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

/**
 * An imported client's opening credit balance (`credit_balance`, client-import spec §6.12). Attributes: `amount`
 * (minor units, zero or more) and `currency` (the currency the file gives it in; null means the client's); link
 * `client_id`.
 *
 * The client's import entries (reason `import`) add up to the balance imports gave it. A run posts the difference to
 * the file's amount as one entry, "Opening balance from <source>", referenced `import` / `run:<run>:client:<client>`
 * (unique, so one run never posts twice for a client). A later run that changes the amount posts the difference only
 * while the client has had no other credit movement since its last import entry; otherwise the balance is kept and
 * the row says so. History is never edited. reverse() takes back exactly what one run posted while the balance still
 * covers it (undo, §5.7).
 *
 * Lock order (ADR 0021 §3, import): the caller holds the run and migration record; this locks the client, and reads
 * the ledger only under that lock, as every other credit movement does.
 */
class OpeningBalanceImporter implements ReversibleImporter
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
        $clientId = (int) ($record->links['client_id'] ?? 0);
        $client = $context->dryRun ? Client::query()->find($clientId) : Client::query()->whereKey($clientId)->lockForUpdate()->first();

        if ($client === null) {
            return new ImportOutcome(null, ImportAction::Skipped, [$this->issue(ImportSeverity::Error, 'credit_no_client', __('The opening balance was not imported: the client was not found.'))]);
        }

        if (strtoupper((string) ($record->attributes['currency'] ?? $client->currency)) !== $client->currency) {
            return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_currency', __('The opening balance was not imported: it is not in the client\'s currency.'))]);
        }

        $imports = CreditLedgerEntry::query()->where('client_id', $client->id)->where('currency', $client->currency)->where('reason', CreditReason::Import);
        $imported = (int) (clone $imports)->sum('amount');
        $difference = (int) $record->attributes['amount'] - $imported;
        $reference = $this->reference($context->runId, $client->id);

        if ($difference === 0 || (clone $imports)->where('gateway', self::GATEWAY)->where('external_ref', $reference)->exists()) {
            return new ImportOutcome($client->id, ImportAction::Unchanged);
        }

        $lastImport = (int) (clone $imports)->max('id');

        if ($lastImport > 0 && CreditLedgerEntry::query()->where('client_id', $client->id)->where('id', '>', $lastImport)->exists()) {
            return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_kept', __('The opening balance in the file differs, but the client\'s credit has moved since the last import, so it was kept. Adjust it on the client\'s credit page if needed.'))]);
        }

        $action = $lastImport > 0 ? ImportAction::Updated : ImportAction::Created;

        if (! $context->dryRun) {
            $source = (string) ($context->options['source_name'] ?? '');
            $this->ledger->post($client->id, Money::of($difference, $client->currency), CreditReason::Import,
                description: $lastImport > 0 ? __('Opening balance from :source, changed by a later import', ['source' => $source]) : __('Opening balance from :source', ['source' => $source]),
                staffId: $context->actorId, gateway: self::GATEWAY, externalRef: $reference);
        }

        return new ImportOutcome($client->id, $action, changes: ['credit_balance'], before: ['credit_balance' => $imported]);
    }

    public function reverse(int $targetId, ImportContext $context): ImportOutcome
    {
        $client = $context->dryRun ? Client::query()->find($targetId) : Client::query()->whereKey($targetId)->lockForUpdate()->first();
        $posted = $client === null ? null : CreditLedgerEntry::query()->where('client_id', $client->id)
            ->where('gateway', self::GATEWAY)->where('external_ref', $this->reference($context->runId, $client->id))->first();

        if ($client === null || $posted === null
            || CreditLedgerEntry::query()->where('gateway', self::GATEWAY)->where('external_ref', 'undo:'.$this->reference($context->runId, $client->id))->exists()) {
            return new ImportOutcome($client?->id, ImportAction::Unchanged);
        }

        $back = Money::of(-$posted->amount, $posted->currency);

        if ($back->isNegative() && $this->ledger->balance($client->id, $posted->currency)->add($back)->isNegative()) {
            return new ImportOutcome($client->id, ImportAction::Skipped, [$this->issue(ImportSeverity::Warning, 'credit_used', __('The opening balance was kept: the client has already used part of it.'))]);
        }

        if (! $context->dryRun) {
            $this->ledger->post($client->id, $back, CreditReason::Import, description: __('Opening balance reversed: the import was undone'),
                staffId: $context->actorId, gateway: self::GATEWAY, externalRef: 'undo:'.$this->reference($context->runId, $client->id));
        }

        return new ImportOutcome($client->id, ImportAction::Updated, changes: ['credit_balance']);
    }

    public function finalize(ImportContext $context): array
    {
        return [];
    }

    public function cutover(ImportContext $context): int
    {
        return 0;
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
