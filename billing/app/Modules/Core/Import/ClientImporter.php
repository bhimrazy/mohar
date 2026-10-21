<?php

namespace App\Modules\Core\Import;

use App\Contracts\Migration\RecordImporter;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Enums\ImportSeverity;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\ClientMarketingConsent;
use App\Modules\Core\Support\ClientRules;
use App\Support\Clients\ClientCurrencyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Writes imported clients (`client`, client-import spec §6). Attributes: the client fields, `client_group_name`
 * (created when no group has that name), `signup_date`, `imported_fields`, `marketing_consent` (granted|withdrawn)
 * with `marketing_consent_date` and `marketing_consent_evidence`, and `blank` (fields whose cells were empty). It
 * saves through the model, so a new client's email records its source; the model dispatches no domain event, so
 * nothing is mailed and no `ClientRegistered` fires. An update never changes the email, nor any login.
 */
class ClientImporter implements RecordImporter
{
    /** Fields an update may empty when the file's cell is blank and the import clears blank cells. */
    public const CLEARABLE = ['company_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'tax_id', 'client_group_id', 'notes'];

    public const FIELDS = ['first_name', 'last_name', 'company_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state',
        'postcode', 'country', 'tax_id', 'tax_exempt', 'currency', 'client_group_id', 'language', 'status', 'notes'];

    public function key(): string
    {
        return 'client';
    }

    public function rules(): array
    {
        return [...ClientRules::fields(), 'status' => ['required', 'in:'.implode(',', Client::STATUSES)], 'currency' => ['required', 'string', 'size:3']];
    }

    public function validate(ImportRecord $record, ImportContext $context): array
    {
        return [];
    }

    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
    {
        $attributes = $record->attributes;
        $fields = Arr::only($attributes, self::FIELDS);
        $issues = [];

        if (($attributes['client_group_name'] ?? null) !== null) {
            // A dry run creates no group: it uses one that exists, or none.
            $fields['client_group_id'] = $this->group((string) $attributes['client_group_name'], create: ! $context->dryRun);
        }

        if ($existingTargetId === null && $context->dryRun) {
            return new ImportOutcome(null, ImportAction::Created);
        }

        if ($existingTargetId === null) {
            $client = new Client(Arr::except($fields, ['email', 'client_group_id']));
            $client->setEmailBy(EmailSource::Import, (string) $fields['email'])->forceFill([
                'client_group_id' => $fields['client_group_id'] ?? null,
                'origin' => ClientOrigin::Import,
                'imported_at' => $context->importedAt,
                'imported_fields' => $this->importedFields([], $attributes, $context) ?: null,
                'closed_at' => ($fields['status'] ?? null) === 'closed' ? $context->importedAt : null,
                'created_at' => isset($attributes['signup_date']) ? CarbonImmutable::parse((string) $attributes['signup_date']) : $context->importedAt,
            ])->save();
            $this->consent($client, $attributes, $context, true, $issues);

            return new ImportOutcome($client->id, ImportAction::Created, $issues);
        }

        $client = $context->dryRun ? Client::query()->findOrFail($existingTargetId) : Client::query()->lockForUpdate()->findOrFail($existingTargetId);
        $blank = array_map(fn (string $target): string => $target === 'client_group' ? 'client_group_id' : $target, $attributes['blank'] ?? []);

        // Only the fields the file maps change; a blank cell empties its field only when the import clears blanks.
        if (($context->options['blank_cells'] ?? 'keep') === 'clear') {
            $fields = [...array_fill_keys(array_intersect($blank, self::CLEARABLE), null), ...$fields];
        }

        // A re-run never reopens a client closed here, and an import never changes an existing client's email.
        if ($client->status === 'closed') {
            unset($fields['status']);
        }

        if (isset($fields['email']) && mb_strtolower((string) $fields['email']) !== mb_strtolower($client->email)) {
            $issues[] = new ImportIssue(ImportSeverity::Info, 'email_kept', __('Email in the file differs: kept the current one.'));
        }

        unset($fields['email']);

        // Amounts are stored in the client's currency: it cannot change once any module holds amounts for the client (§6.4).
        if (isset($fields['currency']) && $fields['currency'] !== $client->currency && app(ClientCurrencyUsage::class)->clientsInUse([$client->id]) !== []) {
            $issues[] = new ImportIssue(ImportSeverity::Warning, 'currency_kept', __('The currency was kept: this client already has invoices, services, orders, payments or credit in it.'));
            unset($fields['currency']);
        }

        $changes = array_values(array_filter(array_keys($fields), fn (string $field): bool => $fields[$field] === null
            ? ! in_array($client->getAttribute($field), [null, ''], true)
            : (string) $client->getAttribute($field) !== (string) $fields[$field]));
        if ($context->dryRun) {
            return new ImportOutcome($client->id, $changes === [] ? ImportAction::Unchanged : ImportAction::Updated, $issues, $changes);
        }

        // The undo snapshot is read here, under the row lock taken above, so no edit can land between it and the write.
        $snapshot = $client->only([...$changes, 'imported_fields']);
        $client->forceFill(Arr::only($fields, $changes));

        $client->imported_fields = $this->importedFields($client->imported_fields ?? [], $attributes, $context) ?: null;

        if ($client->isDirty('imported_fields')) {
            $changes[] = 'imported_fields';
        }

        $client->save();
        $this->consent($client, $attributes, $context, false, $issues);

        return $changes === []
            ? new ImportOutcome($client->id, ImportAction::Unchanged, $issues)
            : new ImportOutcome($client->id, ImportAction::Updated, $issues, $changes, Arr::only($snapshot, $changes));
    }

    public function finalize(ImportContext $context): array
    {
        return [];
    }

    public function cutover(ImportContext $context): int
    {
        return 0;
    }

    private function group(string $name, bool $create): ?int
    {
        $id = ClientGroup::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->value('id');

        return $id !== null ? (int) $id : ($create ? ClientGroup::query()->create(['name' => $name])->id : null);
    }

    /**
     * The client's imported fields with this source's values replacing the ones it imported before.
     *
     * @param  list<array{label: string, value: string, source: string}>  $current
     * @param  array<string, mixed>  $attributes
     * @return list<array{label: string, value: string, source: string}>
     */
    private function importedFields(array $current, array $attributes, ImportContext $context): array
    {
        $source = (string) ($context->options['source_name'] ?? $context->sourceSystem);
        $incoming = array_map(fn (array $field): array => ['label' => (string) $field['label'], 'value' => (string) $field['value'], 'source' => $source], $attributes['imported_fields'] ?? []);

        return array_values(array_slice([...array_filter($current, fn (array $field): bool => $field['source'] !== $source), ...$incoming], 0, 50));
    }

    /**
     * One consent row when the file says yes or no and that changes the state. An import never turns a withdrawal
     * recorded here into a grant.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<ImportIssue>  $issues
     */
    private function consent(Client $client, array $attributes, ImportContext $context, bool $created, array &$issues): void
    {
        $state = $attributes['marketing_consent'] ?? null;
        $current = $created ? null : ClientMarketingConsent::currentState($client->id);

        if (! in_array($state, ['granted', 'withdrawn'], true) || $state === $current) {
            return;
        }

        if ($state === 'granted' && $current === 'withdrawn') {
            $issues[] = new ImportIssue(ImportSeverity::Warning, 'consent_kept', __('The client withdrew marketing consent here, so the file\'s yes was not applied.'));

            return;
        }

        $source = (string) ($context->options['source_name'] ?? $context->sourceSystem);
        $date = $attributes['marketing_consent_date'] ?? null;
        ClientMarketingConsent::query()->create([
            'client_id' => $client->id,
            'state' => $state,
            'source' => 'import_'.$context->sourceSystem,
            'evidence' => mb_substr((string) ($attributes['marketing_consent_evidence'] ?? ($date === null
                ? __('Imported from :source; the date of consent was not in the file.', ['source' => $source])
                : __('Imported from :source; the file gave the date of consent.', ['source' => $source]))), 0, 500),
            'consented_at' => $date,
            'recorded_at' => $context->importedAt,
            'recorded_by' => $context->actorId,
            'migration_run_id' => $context->runId,
        ]);
    }
}
