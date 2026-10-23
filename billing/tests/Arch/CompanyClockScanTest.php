<?php

/*
 * Billing's document paths take their dates from the company clock (ADR 0013 Amendment 1), never from the UTC calendar.
 * The arch plugin can't see static calls, so this scans the code (comments left out) for the forms that read a UTC date.
 * Factories and seeders (`Database/`) build demo data and are not document paths. Services has joined; the other modules join as
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
    'date() of the current time' => '/(?<![>:$\w])date\s*\(\s*["\x27][^"\x27]*["\x27]\s*\)/',
    'the UTC date of an instant column' => '/_at\s*\??->\s*toDateString\s*\(/',
];

/** Modules that have moved onto the company clock. */
const SCANNED_MODULES = ['Billing', 'Services'];

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
    "\$request->date('valid_until')",
];

/**
 * @return array<string, string> file => code without comments
 */
function billingDocumentCode(string $module = 'Billing', bool $withDatabase = false): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Modules'.($module === '' ? '' : "/{$module}"), FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || (! $withDatabase && str_contains($path, '/Database/'))) {
            continue;
        }

        $files[substr($path, strlen(dirname(__DIR__, 2).'/app') + 1)] = implode('', array_map(
            fn (PhpToken $token): string => in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token->text,
            PhpToken::tokenize((string) file_get_contents($path)),
        ));
    }

    return $files;
}

test('document paths of the moved modules read no UTC calendar date', function () {
    $found = [];

    foreach (SCANNED_MODULES as $module) {
        foreach (billingDocumentCode($module) as $path => $code) {
            foreach (UTC_DATE_FORMS as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $found[] = "{$path}: {$label}";
                }
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

const HAND_CONVERSIONS = [
    'shiftTimezone(' => '/shiftTimezone\s*\(/',
    // A chain such as `$today->subDays(3)->endOfDay()` is the same conversion.
    'an instant method on the run date' => '/\$today(\s*->\s*\w+\([^)]*\))*\s*->\s*(setTime|setTimeFrom|endOfDay|isToday|isSameDay|utc|setTimezone)\s*\(/',
    'a timestamp column compared with the run date' => '/[\x27"]\w+_at[\x27"]\s*,\s*[\x27"][<>]=?[\x27"]\s*,\s*\$today\b/',
];

/**
 * Hits that exist today, as "file: form" => count. The list may only shrink: each module PR removes its own entries. A
 * hit beyond the count fails (use todayDate() and dayBounds()); a listed hit that is gone fails until it is lowered here.
 * The run date is a date value (CompanyClock::todayDate()); a CalendarDate type is deferred to ADR 0013 Amendment 2.
 */
const HAND_CONVERSION_BASELINE = [
    'Modules/Billing/Database/Seeders/BillingHistorySeeder.php: an instant method on the run date' => 1,
    'Modules/Domains/DomainsServiceProvider.php: a timestamp column compared with the run date' => 1,
    'Modules/Notifications/Actions/PruneNotificationLogs.php: a timestamp column compared with the run date' => 2,
    'Modules/Orders/Actions/CancelStalePendingOrders.php: a timestamp column compared with the run date' => 1,
    'Modules/Platform/PlatformServiceProvider.php: a timestamp column compared with the run date' => 3,
];

test('no module converts the run date by hand beyond the baseline', function () {
    $found = [];

    foreach (billingDocumentCode('', true) as $path => $code) {
        foreach (HAND_CONVERSIONS as $label => $pattern) {
            $hits = preg_match_all($pattern, $code);

            if ($hits > 0) {
                $found["{$path}: {$label}"] = $hits;
            }
        }
    }

    ksort($found);
    $baseline = HAND_CONVERSION_BASELINE;
    ksort($baseline);

    $over = array_filter($found, fn (int $hits, string $key): bool => $hits > ($baseline[$key] ?? 0), ARRAY_FILTER_USE_BOTH);
    $under = array_filter($baseline, fn (int $hits, string $key): bool => $hits > ($found[$key] ?? 0), ARRAY_FILTER_USE_BOTH);

    expect($over)->toBe([], 'New hand conversion: use CompanyClock::todayDate() and dayBounds().')
        ->and($under)->toBe([], 'A baselined hit is gone: lower or remove it in HAND_CONVERSION_BASELINE.');
});

test('the hand-conversion patterns see what they ban', function (string $label, string $code) {
    expect(preg_match(HAND_CONVERSIONS[$label], $code))->toBe(1, "{$label}: {$code}");
})->with([
    ['shiftTimezone(', "\$d->shiftTimezone('UTC')"],
    ['an instant method on the run date', '$today->endOfDay()'],
    ['an instant method on the run date', '$today->setTimeFrom(now())'],
    ['an instant method on the run date', '$today->isToday()'],
    ['an instant method on the run date', '$today->subDays(3)->endOfDay()'],
    ['an instant method on the run date', '$today->addDay()->setTimeFrom(now())'],
    ['a timestamp column compared with the run date', "->where('captured_at', '>=', \$today)"],
    ['a timestamp column compared with the run date', "->where('created_at', '<', \$today->subDays(3))"],
]);

test('the scan reads the moved modules but not their factories', function () {
    expect(array_keys(billingDocumentCode()))->toContain('Modules/Billing/Actions/PublishInvoice.php')->not->toContain('Modules/Billing/Database/Factories/InvoiceFactory.php')
        ->and(array_keys(billingDocumentCode('Services')))->toContain('Modules/Services/Actions/PruneModuleLog.php')->not->toContain('Modules/Services/Database/Factories/ServiceFactory.php');
});

afterAll(fn () => releaseArchCache());
