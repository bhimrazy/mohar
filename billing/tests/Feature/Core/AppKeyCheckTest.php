<?php

/*
 * The APP_KEY line of the install pre-flight and APP_KEY_FILE (#25).
 */

use App\Modules\Core\Install\AppKeyCheck;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\EnvironmentCheck;
use App\Support\Security\SecretEnv;

function withEnv(array $values, Closure $callback): mixed
{
    foreach ($values as $name => $value) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach (array_keys($values) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}

function keyCheck(string $key, string $env = 'production', array $previous = []): EnvironmentCheck
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->detectEnvironment(fn () => $env);

    return (new AppKeyCheck)->run();
}

test('APP_KEY_FILE supplies the key when APP_KEY is empty, and APP_KEY wins when both are set', function () {
    $file = tempnam(sys_get_temp_dir(), 'key');
    file_put_contents($file, "base64:from-file\n");

    try {
        expect(withEnv(['APP_KEY' => '', 'APP_KEY_FILE' => $file], fn () => SecretEnv::get('APP_KEY')))->toBe('base64:from-file')
            ->and(withEnv(['APP_KEY' => 'base64:from-env', 'APP_KEY_FILE' => $file], fn () => SecretEnv::get('APP_KEY')))->toBe('base64:from-env')
            ->and(withEnv(['APP_KEY' => '', 'APP_KEY_FILE' => $file.'.missing'], fn () => SecretEnv::get('APP_KEY')))->toBeNull();
    } finally {
        unlink($file);
    }
});

test('an unreadable APP_KEY_FILE fails the check with its path', function () {
    $check = withEnv(['APP_KEY' => '', 'APP_KEY_FILE' => '/run/secrets/nope'], fn () => keyCheck(''));

    expect($check->status)->toBe(CheckStatus::Failed)
        ->and($check->detail)->toContain('APP_KEY_FILE is set to /run/secrets/nope');
});

test('in production a published or non-random key is a warning', function () {
    $placeholder = keyCheck('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    $patterned = keyCheck('base64:'.base64_encode(str_repeat('ab', 16)));

    expect($placeholder->status)->toBe(CheckStatus::Warning)
        ->and($placeholder->detail)->toContain('published key')
        ->and($patterned->status)->toBe(CheckStatus::Warning)
        ->and($patterned->detail)->toContain('not random')
        ->and(keyCheck('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', env: 'local')->status)->toBe(CheckStatus::Ok);
});

test('a random key is fine, and previous keys are reminded and checked', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    $old = 'base64:'.base64_encode(random_bytes(32));

    expect(keyCheck($key)->status)->toBe(CheckStatus::Ok)
        ->and(keyCheck($key, previous: [$old])->detail)->toContain('1 previous key(s): run `php artisan security:reencrypt`')
        ->and(keyCheck($key, previous: [$key])->detail)->toContain('contains the current APP_KEY')
        ->and(keyCheck($key, previous: ['base64:short'])->detail)->toContain('does not fit the cipher');
});

test('every APP_KEY committed to the repository is recognised as published', function () {
    $root = dirname(base_path());
    $files = [...glob($root.'/deploy/{,*/}*', GLOB_BRACE) ?: [], base_path('.env.example'), base_path('phpunit.xml')];

    foreach (array_filter($files, is_file(...)) as $file) {
        preg_match_all('/APP_KEY=["\']?base64:([A-Za-z0-9+\/=]+)/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $encoded) {
            expect(in_array(hash('sha256', base64_decode($encoded)), AppKeyCheck::PUBLIC_KEY_HASHES, true))->toBeTrue("{$file} commits an APP_KEY that AppKeyCheck does not know");
        }
    }
});
