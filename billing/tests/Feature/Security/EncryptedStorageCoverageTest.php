<?php

/*
 * `security:reencrypt` must cover every value encrypted with APP_KEY (#25). These read the source, independently of
 * the command's own model discovery, so a new encrypted cast or a new direct Crypt call fails here until it is covered.
 */

use App\Modules\Core\Security\EncryptedTargets;
use Symfony\Component\Finder\Finder;

/** @return iterable<string, string> relative path => source, migrations excluded */
function appSources(): iterable
{
    foreach ((new Finder)->files()->name('*.php')->in(app_path())->notPath('#Database/Migrations#')->sortByName() as $file) {
        yield $file->getRelativePathname() => $file->getContents();
    }
}

test('every encrypted cast in the code is a re-encryption target', function () {
    $targets = array_keys(app(EncryptedTargets::class)->all());
    $declared = [];

    foreach (appSources() as $path => $source) {
        if (preg_match_all("/['\"](\\w+)['\"]\\s*=>\\s*(?:['\"]encrypted(?::\\w+)?['\"]|AsEncrypted\\w+::(?:class|of\\())/", $source, $matches) === 0) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $path);
        expect(class_exists($class))->toBeTrue("{$path} declares an encrypted cast but is not an autoloadable class");

        foreach ($matches[1] as $column) {
            $declared[] = (new $class)->getTable().'.'.$column;
        }
    }

    expect($declared)->toHaveCount(25)
        ->and(array_values(array_diff($declared, $targets)))->toBe([], 'Encrypted casts security:reencrypt does not cover: register the model in EncryptedStorage (new EncryptedModel(...))');
});

test('every direct use of the encrypter is covered by a target or known to be short-lived', function () {
    // File => the target that re-encrypts what it writes, or why nothing needs to (the runbook lists the short-lived ones).
    $known = [
        'Modules/Core/Support/Settings.php' => 'settings.value',
        'Modules/Kyc/Services/DocumentVault.php' => 'kyc_documents.files',
        'Modules/Kyc/Services/StoredImageReencoder.php' => 'kyc_documents.files',
        'Modules/Migration/Jobs/StageCsvRun.php' => 'migration_records.raw',
        'Modules/Migration/Jobs/CheckRun.php' => 'migration_records.normalized',
        'Modules/Orders/Data/CartItem.php' => 'short-lived: carts expire, and an unreadable EPP code is asked for again',
        'Modules/Migration/Support/EncryptedFile.php' => 'short-lived: an import upload is deleted once staged',
        'Modules/Core/Security/Reencryptor.php' => 'the re-encryption itself',
        'Modules/Core/Support/InstallSecrets.php' => 'install_secrets.value',
    ];
    $targets = array_keys(app(EncryptedTargets::class)->all());
    $found = [];

    foreach (appSources() as $path => $source) {
        if (preg_match('/Crypt::encrypt|\bencrypt\(|->encrypt\(/', $source) === 1) {
            $found[] = $path;
        }
    }

    expect(array_values(array_diff($found, array_keys($known))))->toBe([], 'New code encrypts with APP_KEY: register its storage in EncryptedStorage or list it here with the reason');

    foreach ($known as $path => $reason) {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $reason) === 1) {
            expect($targets)->toContain($reason);
        }
    }
});

test('nothing else derives stored values from APP_KEY', function () {
    // A value keyed from APP_KEY changes with every rotation: store it under an install secret (InstallSecrets) or
    // list it here with why a change is harmless.
    $known = [
        'Modules/Core/Install/AppKeyCheck.php' => 'checks the key',
        'Modules/Core/Console/Commands/ReencryptCommand.php' => 'checks the key is set',
        'Modules/Core/Security/Reencryptor.php' => 'the re-encryption itself',
        'Modules/Core/Support/AccountLinks.php' => 'signed links, which the previous keys still verify',
        'Modules/Kyc/Actions/DecideDocument.php' => 'a confirm-then-apply digest, valid for one decision',
        'Modules/Billing/Drivers/Gateways/SandboxCardDriver.php' => 'the sandbox gateway, development only',
        'Modules/Core/Support/InstallSecrets.php' => 'passes the configured keys to a secret\'s initial value, once',
    ];
    $found = [];

    foreach (appSources() as $path => $source) {
        if (preg_match('/config\(\s*[\'"]app\.(key|previous_keys)[\'"]/', $source) === 1) {
            $found[] = $path;
        }
    }

    expect(array_values(array_diff($found, array_keys($known))))->toBe([]);
});
