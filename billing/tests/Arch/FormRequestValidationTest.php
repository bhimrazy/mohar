<?php

/*
 * Controllers validate through Form Requests, which also own the authorization of the action (architecture.md,
 * "Validation and authorization"; best-practices audit 2026-10-31, M1). This scans controller code, comments left
 * out, for inline validation. Billing is clean; the other controllers are held at their current count until each
 * module moves.
 */

const INLINE_VALIDATION = '/->\s*validate(WithBag|With)?\s*\(|\bValidator\s*::\s*(make|validate)\s*\(|(?<![>:$\w])validator\s*\(|\bapp\s*\(\s*[\'"]validator[\'"]\s*\)/';

/** The Validator facade imported under another name: `use Illuminate\Support\Facades\Validator as V;`. */
const VALIDATOR_ALIAS = '/^use\s+Illuminate\\\\Support\\\\Facades\\\\Validator\s+as\s+(\w+)\s*;/m';

/** Inline validation calls left, as "file" => count. Lower an entry when a controller moves; never raise one. */
const INLINE_VALIDATION_BASELINE = [
    'Http/Controllers/Settings/SetPasswordController.php' => 1,
    'Modules/Domains/Http/Controllers/Public/DomainSearchController.php' => 2,
    'Modules/Kyc/Http/Controllers/Admin/OrphanFileController.php' => 1,
    'Modules/Kyc/Http/Controllers/Admin/ReviewController.php' => 2,
    'Modules/Kyc/Http/Controllers/Client/VerificationController.php' => 1,
    'Modules/Migration/Http/Controllers/Admin/ClientImportController.php' => 5,
    'Modules/Migration/Http/Controllers/Admin/ImportInvitationController.php' => 2,
    'Modules/Notifications/Http/Controllers/Admin/EmailTemplateController.php' => 1,
    'Modules/Notifications/Http/Controllers/Admin/StaffSubscriptionController.php' => 1,
    'Modules/Orders/Http/Controllers/Admin/OrderController.php' => 1,
    'Modules/Orders/Http/Controllers/Public/StoreController.php' => 2,
    'Modules/Platform/Http/Controllers/Admin/ActivityLogController.php' => 1,
    'Modules/Platform/Http/Controllers/Admin/AutomationController.php' => 2,
    'Modules/Platform/Http/Controllers/Admin/ClientLifecycleController.php' => 1,
    'Modules/Platform/Http/Controllers/Admin/ImpersonationController.php' => 1,
    'Modules/Platform/Http/Controllers/Admin/MailTestController.php' => 1,
    'Modules/Services/Http/Controllers/Admin/ServerController.php' => 1,
    'Modules/Services/Http/Controllers/Admin/ServerImportController.php' => 3,
    'Modules/Storefront/Http/Controllers/Admin/WebsiteController.php' => 1,
    'Modules/Support/Http/Controllers/Admin/CannedReplyCategoryController.php' => 1,
    'Modules/Support/Http/Controllers/Admin/NetworkIncidentController.php' => 1,
    'Modules/Support/Http/Controllers/Admin/StaffSignatureController.php' => 1,
    'Modules/Support/Http/Controllers/Public/KnowledgebaseController.php' => 1,
    'Modules/Themes/Http/Controllers/Admin/ThemeController.php' => 1,
];

/**
 * @return array<string, int> file relative to app/ => inline validation calls
 */
function inlineValidationCalls(): array
{
    $app = dirname(__DIR__, 2).'/app';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || ! str_contains($path, '/Http/Controllers/')) {
            continue;
        }

        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }

        $hits = inlineValidationHits($code);

        if ($hits > 0) {
            $found[substr($path, strlen($app) + 1)] = $hits;
        }
    }

    ksort($found);

    return $found;
}

function inlineValidationHits(string $code): int
{
    $hits = preg_match_all(INLINE_VALIDATION, $code);

    if (preg_match_all(VALIDATOR_ALIAS, $code, $aliases) > 0) {
        foreach ($aliases[1] as $alias) {
            $hits += preg_match_all('/\b'.preg_quote($alias, '/').'\s*::\s*(make|validate)\s*\(/', $code);
        }
    }

    return $hits;
}

test('controllers validate through Form Requests beyond the baseline', function () {
    $found = inlineValidationCalls();
    $over = array_filter($found, fn (int $hits, string $file): bool => $hits > (INLINE_VALIDATION_BASELINE[$file] ?? 0), ARRAY_FILTER_USE_BOTH);
    $under = array_filter(INLINE_VALIDATION_BASELINE, fn (int $hits, string $file): bool => $hits > ($found[$file] ?? 0), ARRAY_FILTER_USE_BOTH);

    expect($over)->toBe([], 'Move this validation into a Form Request that owns the authorization (architecture.md).')
        ->and($under)->toBe([], 'A baselined call is gone: lower or remove its entry in INLINE_VALIDATION_BASELINE.');
});

test('the scan sees each form of inline validation', function () {
    foreach ([
        '$request->validate([',
        '$request->validateWithBag(\'x\', [',
        '$this->validateWith($validator, $request);',
        'Validator::make($data, [',
        'validator($data, [',
        "app('validator')->make(\$data, [",
        "use Illuminate\\Support\\Facades\\Validator as Checks;\n\$v = Checks::make(\$data, [",
    ] as $code) {
        expect(inlineValidationHits($code))->toBe(1, $code);
    }

    expect(inlineValidationHits('$request->validated()'))->toBe(0)
        ->and(inlineValidationHits('$this->validator->check()'))->toBe(0);
});

afterAll(fn () => releaseArchCache());
