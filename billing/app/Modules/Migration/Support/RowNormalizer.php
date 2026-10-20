<?php

namespace App\Modules\Migration\Support;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Turns one staged row into client attributes under a mapping (client-import spec §6), with the row's problems. It
 * runs no query: currencies, groups and locales come in from the chunk. Problems carry field names and fixed
 * sentences, never cell values.
 */
final class RowNormalizer
{
    private const BOOLEANS = ['1' => true, 'yes' => true, 'y' => true, 'true' => true, 'on' => true, 'checked' => true,
        '0' => false, 'no' => false, 'n' => false, 'false' => false, 'off' => false, 'unchecked' => false];

    private const DATE_PATTERNS = [
        'Y-m-d' => ['/^(\d{4})-(\d{1,2})-(\d{1,2})$/', 1, 2, 3],
        'd/m/Y' => ['/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', 3, 2, 1],
        'm/d/Y' => ['/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', 3, 1, 2],
    ];

    /** @var list<array{severity: string, code: string, message: string, field?: string}> */
    private array $problems = [];

    /** @var array<string, list<string>> */
    private array $found = [];

    /**
     * @param  list<string>  $header
     * @param  list<string>  $columns  the target of each column
     * @param  array<string, string>  $currencies  file code => enabled code (enabled codes map to themselves)
     * @param  array<string, int|string>  $groups  lowercase name => group id, "create" or "none"
     * @param  list<string>  $locales
     */
    public function __construct(
        private array $header,
        private array $columns,
        private ?string $dateFormat,
        private array $currencies,
        private string $defaultCurrency,
        private array $groups,
        private array $locales,
    ) {}

    /**
     * @param  array<int, string>  $raw  cells by column position
     * @return array{client: array<string, mixed>, defaults: array<string, mixed>, extra: array<string, mixed>, blank: list<string>, problems: list<array{severity: string, code: string, message: string, field?: string}>, found: array<string, list<string>>}
     */
    public function normalize(array $raw): array
    {
        $this->problems = [];
        $this->found = [];
        // Only mapped, non-blank cells become client values; defaults apply when a row creates a client, never to an update.
        $client = [];
        $defaults = ['tax_exempt' => false, 'currency' => $this->defaultCurrency, 'status' => 'active', 'language' => $this->locales[0]];
        $extra = ['imported_fields' => []];
        $blank = [];

        if ($raw !== [] && max(array_keys($raw)) >= count($this->header)) {
            $this->problem('error', 'extra_cells', __('The row has more cells than the file has columns.'));
        }

        foreach ($this->columns as $index => $target) {
            if ($target === ClientColumns::IGNORE) {
                continue;
            }

            $value = $this->clean($raw[$index] ?? '', $target === 'notes');

            if ($value === '') {
                $blank[] = $target;

                continue;
            }

            match ($target) {
                ClientColumns::IMPORTED_FIELD => mb_strlen($value) > 1000
                    ? $this->problem('error', 'too_long', __('An imported field is longer than 1,000 characters.'), $target)
                    : $extra['imported_fields'][] = ['label' => trim($this->header[$index] ?? '') ?: __('Column :number', ['number' => $index + 1]), 'value' => $value],
                ClientColumns::EXTERNAL_ID => mb_strlen($value) > 191
                    ? $this->problem('error', 'too_long', __('The External ID is longer than 191 characters.'), $target)
                    : $extra[$target] = $value,
                'email' => $client['email'] = mb_strtolower($value),
                'tax_exempt' => $client['tax_exempt'] = $this->boolean($value, $target),
                'email_verified' => $extra[$target] = $this->boolean($value, $target),
                'marketing_consent' => $extra[$target] = match ($this->boolean($value, $target)) {
                    true => 'granted', false => 'withdrawn', null => null,
                },
                'signup_date', 'marketing_consent_date' => $extra[$target] = $this->date($value, $target),
                'marketing_consent_evidence' => mb_strlen($value) > 500
                    ? $this->problem('error', 'too_long', __('The consent evidence is longer than 500 characters.'), $target)
                    : $extra[$target] = $value,
                'currency' => $client['currency'] = $this->currency($value),
                'client_group' => $client = [...$client, ...$this->group($value)],
                'country' => $client['country'] = $this->country($value),
                'status' => $client['status'] = $this->status($value),
                'language' => $client['language'] = $this->language($value),
                default => $client[$target] = $value,
            };
        }

        $this->spreadsheetArtefacts($client);
        $validator = Validator::make([...$defaults, ...$client], ClientRules::fields())
            ->setAttributeNames(array_map(mb_strtolower(...), ClientColumns::TARGETS));

        foreach ($validator->errors()->messages() as $field => $messages) {
            $this->problem('error', 'invalid', $messages[0], $field);
        }

        return ['client' => $client, 'defaults' => $defaults, 'extra' => $extra, 'blank' => $blank, 'problems' => $this->problems, 'found' => $this->found];
    }

    private function clean(string $value, bool $multiline): string
    {
        return trim((string) preg_replace($multiline ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', ' ', $value));
    }

    private function boolean(string $value, string $field): ?bool
    {
        $boolean = self::BOOLEANS[mb_strtolower($value)] ?? null;

        if ($boolean === null) {
            $this->problem('error', 'not_yes_no', __('The :field column must say yes or no.', ['field' => mb_strtolower(ClientColumns::TARGETS[$field])]), $field);
        }

        return $boolean;
    }

    private function date(string $value, string $field): ?string
    {
        [$pattern, $year, $month, $day] = self::DATE_PATTERNS[$this->dateFormat ?? 'Y-m-d'];
        $matched = preg_match($pattern, explode(' ', str_replace('T', ' ', $value))[0], $parts) === 1;

        if (! $matched || ! checkdate((int) $parts[$month], (int) $parts[$day], (int) $parts[$year])) {
            $this->problem('error', 'bad_date', __('The :field is not a date in the chosen format.', ['field' => mb_strtolower(ClientColumns::TARGETS[$field])]), $field);

            return null;
        }

        $date = CarbonImmutable::create((int) $parts[$year], (int) $parts[$month], (int) $parts[$day]);

        if ($date === null || $date->isFuture()) {
            $this->problem('error', 'future_date', __('The :field is in the future.', ['field' => mb_strtolower(ClientColumns::TARGETS[$field])]), $field);

            return null;
        }

        return $date->toDateString();
    }

    private function currency(string $value): string
    {
        $code = strtoupper($value);

        if (! isset($this->currencies[$code])) {
            $this->found['currencies'][] = mb_substr($code, 0, 10);
            $this->problem('error', 'currency', __('The currency is not enabled here. Map it to an enabled currency on the map step.'), 'currency');

            return $this->defaultCurrency;
        }

        return $this->currencies[$code];
    }

    /**
     * @return array{client_group_id: int|null, client_group_create?: string}
     */
    private function group(string $value): array
    {
        $choice = $this->groups[mb_strtolower($value)] ?? null;

        if ($choice === null) {
            $this->found['groups'][] = mb_substr($value, 0, 100);
            $this->problem('error', 'group', __('The client group was not found. Choose what to do with it on the map step.'), 'client_group');
        }

        return match ($choice) {
            'create' => ['client_group_id' => null, 'client_group_create' => mb_substr($value, 0, 100)],
            null, 'none' => ['client_group_id' => null],
            default => ['client_group_id' => (int) $choice],
        };
    }

    private function country(string $value): ?string
    {
        $code = Countries::code($value);

        if ($code === null) {
            $this->found['countries'][] = mb_substr($value, 0, 60);
            $this->problem('error', 'country', __('The country is not a two-letter country code (such as IN, NP or US).'), 'country');
        }

        return $code;
    }

    private function status(string $value): string
    {
        $status = mb_strtolower($value);

        if (! in_array($status, Client::STATUSES, true)) {
            $this->problem('error', 'status', __('The status must be active, inactive or closed.'), 'status');

            return 'active';
        }

        return $status;
    }

    private function language(string $value): string
    {
        if (! in_array(mb_strtolower($value), $this->locales, true)) {
            $this->problem('warning', 'language', __('The language is not offered here; the default language is used.'), 'language');

            return $this->locales[0];
        }

        return mb_strtolower($value);
    }

    /**
     * Values a spreadsheet often mangles: numbers turned into scientific notation, and Indian postcodes that lost
     * their leading zero.
     *
     * @param  array<string, mixed>  $client
     */
    private function spreadsheetArtefacts(array $client): void
    {
        foreach (['phone', 'postcode'] as $field) {
            if (preg_match('/^\d(\.\d+)?E\+?\d+$/i', (string) ($client[$field] ?? '')) === 1) {
                $this->problem('warning', 'scientific', __('The :field looks changed by a spreadsheet (scientific notation). Check the original.', ['field' => mb_strtolower(ClientColumns::TARGETS[$field])]), $field);
            }
        }

        if (($client['country'] ?? null) === 'IN' && preg_match('/^\d{5}$/', (string) ($client['postcode'] ?? '')) === 1) {
            $this->problem('warning', 'postcode', __('Indian postcodes have six digits; a spreadsheet may have dropped a leading zero.'), 'postcode');
        }
    }

    private function problem(string $severity, string $code, string $message, ?string $field = null): null
    {
        $problem = ['severity' => $severity, 'code' => $code, 'message' => $message];

        if ($field !== null) {
            $problem['field'] = $field;
        }

        $this->problems[] = $problem;

        return null;
    }
}
