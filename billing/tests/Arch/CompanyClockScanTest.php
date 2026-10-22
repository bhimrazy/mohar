<?php

/*
 * Billing's document paths take their dates from the company clock (ADR 0013 Amendment 1), never from the UTC calendar.
 * The arch plugin can't see static calls, so this scans the code (comments left out) for the forms that read a UTC date.
 * Factories and seeders (`Database/`) build demo data and are not document paths. The other modules join the scan as
 * each one moves.
 */

const UTC_DATE_FORMS = [
    '::today(' => '/::\s*today\s*\(/',
    'today()' => '/(?<![>:\w$])today\s*\(/',
    '::yesterday( or ::tomorrow(' => '/(::|(?<![>\w$]))\s*(yesterday|tomorrow)\s*\(/',
    // `now()` and `Carbon::now()` read UTC; the company's `$clock->now()` (preceded by `->`) is correct.
    'now() turned into a date' => '/(?<!->)\bnow\s*\([^)]*\)(\s*->\s*\w+\([^)]*\))*\s*->\s*(toDateString|startOfDay|endOfDay|format\(\s*["\x27]Y-m-d)/',
    'whereDate() against now()' => '/whereDate\s*\([^;)]*?(?<!->)\bnow\s*\(/',
    'CURRENT_DATE in SQL' => '/\b(current_date|curdate|current_timestamp)\b|date\(\s*["\x27]now["\x27]/i',
    'a relative date string' => '/\b(parse|create|createFromFormat|new\s+\w+)\s*\(\s*["\x27](today|now|yesterday|tomorrow|midnight)["\x27]/',
    'date() of the current time' => '/\bdate\s*\(\s*["\x27][^"\x27]*["\x27]\s*\)/',
    'the UTC date of an instant column' => '/_at\s*\??->\s*toDateString\s*\(/',
];

/** Code the scan must not flag. */
const ALLOWED_DATE_CODE = [
    '$this->clock->today()',
    'app(CompanyClock::class)->today()',
    '$this->clock->now()->toDateString()',
    '$clock->now()->startOfDay()',
    '$invoice->due_date->toDateString()',
    'now()->subDays(14)',
    "->whereDate('due_date', '<', \$clock->today()->toDateString())",
    '$clock->localDate($transaction->occurred_at)',
];

/**
 * @return array<string, string> file => code without comments
 */
function billingDocumentCode(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Modules/Billing', FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || str_contains($path, '/Billing/Database/')) {
            continue;
        }

        $files[substr($path, strlen(dirname(__DIR__, 2).'/app') + 1)] = implode('', array_map(
            fn (PhpToken $token): string => in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token->text,
            PhpToken::tokenize((string) file_get_contents($path)),
        ));
    }

    return $files;
}

test('Billing document paths read no UTC calendar date', function () {
    $found = [];

    foreach (billingDocumentCode() as $path => $code) {
        foreach (UTC_DATE_FORMS as $label => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = "{$path}: {$label}";
            }
        }
    }

    expect($found)->toBe([], 'Use App\Contracts\Core\CompanyClock::today() for a calendar date.');
});

test('the scan sees every form it forbids', function (string $label, string $code) {
    expect(preg_match(UTC_DATE_FORMS[$label], $code))->toBe(1, "{$label}: {$code}");
})->with([
    ['::today(', 'CarbonImmutable::today()'],
    ['::today(', 'Date::today()'],
    ['::today(', 'C::today()'],
    ['today()', '$d = today();'],
    ['::yesterday( or ::tomorrow(', 'CarbonImmutable::yesterday()'],
    ['::yesterday( or ::tomorrow(', 'Carbon::tomorrow()'],
    ['::yesterday( or ::tomorrow(', '$d = yesterday();'],
    ['now() turned into a date', 'now()->toDateString()'],
    ['now() turned into a date', 'CarbonImmutable::now()->toDateString()'],
    ['now() turned into a date', 'now()->subDay()->toDateString()'],
    ['now() turned into a date', 'now()->startOfDay()'],
    ['now() turned into a date', 'Carbon::now()->endOfDay()'],
    ['now() turned into a date', "CarbonImmutable::now()->format('Y-m-d')"],
    ['whereDate() against now()', "->whereDate('due_date', '<', now())"],
    ['whereDate() against now()', "\$q->whereDate('due_date', '<=', Carbon::now()->addDay())"],
    ['CURRENT_DATE in SQL', "DB::raw('CURRENT_DATE')"],
    ['CURRENT_DATE in SQL', "whereRaw('due_date < current_date')"],
    ['CURRENT_DATE in SQL', "whereRaw(\"due_date < date('now')\")"],
    ['a relative date string', "CarbonImmutable::parse('today')"],
    ['a relative date string', "new CarbonImmutable('yesterday')"],
    ['date() of the current time', "date('Ymd')"],
    ['date() of the current time', "date('Y-m-d')"],
    ['the UTC date of an instant column', '$transaction->occurred_at->toDateString()'],
    ['the UTC date of an instant column', '$invoice->paid_at?->toDateString()'],
]);

test('the scan allows the company clock and plain date handling', function () {
    foreach (ALLOWED_DATE_CODE as $allowed) {
        expect(array_keys(array_filter(UTC_DATE_FORMS, fn (string $pattern): bool => preg_match($pattern, $allowed) === 1)))->toBe([], $allowed);
    }
});

test('the scan reads the Billing module', function () {
    expect(array_keys(billingDocumentCode()))->toContain('Modules/Billing/Actions/PublishInvoice.php')->not->toContain('Modules/Billing/Database/Factories/InvoiceFactory.php');
});

afterAll(fn () => releaseArchCache());
