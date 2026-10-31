<?php

/*
 * Allowed module folders (docs/architecture.md → "Module building blocks"). A directory file scan, not an arch()
 * rule: it checks where files live, not what they import. A new kind of folder needs a line in architecture.md and
 * in ALLOWED_MODULE_FOLDERS in the same PR.
 */

/** The first directory level a module may have. Mirrors the tree in architecture.md. */
const ALLOWED_MODULE_FOLDERS = [
    'Actions', 'Adapters', 'Boot', 'Config', 'Console', 'Data', 'Database', 'Drivers', 'Enums', 'Exceptions',
    'Http', 'Install', 'Jobs', 'Listeners', 'Mail', 'Models', 'Queries', 'Resources', 'Rules', 'Services',
    'Settings', 'Support', 'routes',
];

/**
 * Folders that exist today and are not in the list above. The baseline only shrinks: the PR that moves or
 * documents a folder deletes its line, and a line for a folder that no longer exists fails the stale check.
 * Key: "Module/Folder". Value: the PR in docs/handoff/issue-plan.md (code structure review, §6) that resolves it.
 */
const MODULE_FOLDER_BASELINE = [
    // TODO(S3): Billing sub-domains; fold into Actions/Queries/Support or promote to a documented sub-domain.
    'Billing/Import' => 'S3',
    'Billing/Reports' => 'S3',
    // TODO(S8): Core's Import and Security move with the Core/Platform decision; Core/Notifications -> Mail/.
    'Core/Import' => 'S8',
    'Core/Notifications' => 'S8',
    'Core/Security' => 'S8',
    // TODO(S3): Notifications sub-domains (template engine, webhook delivery).
    'Notifications/Rendering' => 'S3',
    'Notifications/Webhooks' => 'S3',
    // TODO(S3): Orders sub-domains (fraud screening, cart pricing).
    'Orders/Fraud' => 'S3',
    'Orders/Pricing' => 'S3',
    // TODO(S3): Laravel notification classes move to Mail/.
    'Platform/Notifications' => 'S3',
];

/** @return list<string> "Module/Folder" for every first-level directory under app/Modules. */
function moduleFolders(): array
{
    $found = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: [] as $module) {
        foreach (glob($module.'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $found[] = basename($module).'/'.basename($folder);
        }
    }

    sort($found);

    return $found;
}

test('every module folder is allowed or baselined', function () {
    $unknown = array_values(array_filter(
        moduleFolders(),
        fn (string $path): bool => ! in_array(explode('/', $path)[1], ALLOWED_MODULE_FOLDERS, true)
            && ! array_key_exists($path, MODULE_FOLDER_BASELINE),
    ));

    expect($unknown)->toBe([], 'Unknown module folders: add them to architecture.md and ALLOWED_MODULE_FOLDERS, or move the files.');
});

test('the folder baseline only shrinks', function () {
    $present = moduleFolders();
    $stale = array_values(array_filter(
        array_keys(MODULE_FOLDER_BASELINE),
        fn (string $path): bool => ! in_array($path, $present, true)
            || in_array(explode('/', $path)[1], ALLOWED_MODULE_FOLDERS, true),
    ));

    expect($stale)->toBe([], 'Delete these lines from MODULE_FOLDER_BASELINE: the folder is gone or now allowed.');
});

test('the allowed folders are the ones architecture.md lists', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/architecture.md');

    $undocumented = array_values(array_filter(
        ALLOWED_MODULE_FOLDERS,
        fn (string $folder): bool => ! str_contains($doc, "`{$folder}/`"),
    ));

    expect($undocumented)->toBe([]);
});

afterAll(fn () => releaseArchCache());
