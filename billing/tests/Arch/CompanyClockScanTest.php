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
    'now()->toDateString()' => '/\bnow\s*\([^)]*\)\s*->\s*toDateString\s*\(/',
    '::now()->toDateString()' => '/::\s*now\s*\([^)]*\)\s*->\s*toDateString\s*\(/',
    '->startOfDay()->toDateString()' => '/->\s*startOfDay\s*\(\s*\)\s*->\s*toDateString\s*\(/',
    "date('Y-m-d'" => '/\bdate\s*\(\s*[\'"]Y-m-d[\'"]/',
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

test('the scan sees the forms it forbids', function () {
    foreach ([
        '::today(' => 'CarbonImmutable::today()',
        'today()' => '$d = today();',
        'now()->toDateString()' => 'now()->toDateString()',
        '::now()->toDateString()' => 'CarbonImmutable::now()->toDateString()',
        '->startOfDay()->toDateString()' => '$x->startOfDay()->toDateString()',
        "date('Y-m-d'" => "date('Y-m-d')",
    ] as $label => $code) {
        expect(preg_match(UTC_DATE_FORMS[$label], $code))->toBe(1, $label);
    }

    foreach (['$this->clock->today()', 'app(CompanyClock::class)->today()', '$invoice->due_date->toDateString()'] as $allowed) {
        expect(array_filter(UTC_DATE_FORMS, fn (string $pattern): bool => preg_match($pattern, $allowed) === 1))->toBe([], $allowed);
    }
});

test('the scan reads the Billing module', function () {
    expect(array_keys(billingDocumentCode()))->toContain('Modules/Billing/Actions/PublishInvoice.php')->not->toContain('Modules/Billing/Database/Factories/InvoiceFactory.php');
});

afterAll(fn () => releaseArchCache());
