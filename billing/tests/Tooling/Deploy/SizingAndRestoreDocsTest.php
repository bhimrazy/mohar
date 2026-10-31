<?php

/*
 * The server size is written in five places (production.md, install.md, deployment-options.md, sizing.md and the docs
 * site's requirements page), and the restore steps in two (production.md and the docs site). The CEO review of
 * 2026-10-22 found 8 GB in one and 4 GB measured in another; the restore drill found `down -v` leaving the app
 * containers running and `realpath -m` stopping deploy/bin/deploy on macOS. These probes keep the copies together.
 */

function d6Repo(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/'.$path);
}

test('every doc that states a server size gives the same minimum, 2 vCPU and 4 GB', function (string $path) {
    $text = d6Repo($path);

    // The medium profile (4 vCPU, 8 GB) is the only place left that names 8 GB.
    $withoutMedium = (string) preg_replace('/4 vCPU(,| and) 8 GB/', '', $text);

    expect($text)->toMatch('/2 vCPU(,| and| \/) ?4 GB/')
        ->and($withoutMedium)->not->toMatch('/\b8 GB\b/');
})->with([
    'production.md' => 'billing/docs/devops/production.md',
    'install.md' => 'billing/docs/devops/install.md',
    'deployment-options.md' => 'billing/docs/devops/deployment-options.md',
    'sizing.md' => 'billing/docs/devops/sizing.md',
    'requirements page' => 'docs-site/src/content/docs/get-started/requirements.mdx',
]);

test('the restore docs remove a stack with both colour profiles and keep the application key with the backups', function (string $path) {
    $text = d6Repo($path);

    expect($text)->toContain('--profile blue --profile green down -v')
        ->and($text)->toContain('APP_KEY');
})->with([
    'production.md' => 'billing/docs/devops/production.md',
    'docs site' => 'docs-site/src/content/docs/operate/backups.mdx',
]);

test('no restore step removes a stack with a bare `down -v`, which leaves the blue and green containers running', function (string $path) {
    expect(d6Repo($path))->not->toMatch('/compose\.prod\.yaml down -v/');
})->with([
    'production.md' => 'billing/docs/devops/production.md',
    'docs site' => 'docs-site/src/content/docs/operate/backups.mdx',
]);

test('deploy/bin/deploy resolves its state directory without GNU-only realpath options, so it runs on macOS', function () {
    $script = d6Repo('deploy/bin/deploy');

    expect($script)->not->toContain('realpath')
        ->and($script)->toContain('pwd -P');
});
