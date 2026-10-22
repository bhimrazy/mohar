<?php

namespace App\Modules\Core\Install;

use Illuminate\Encryption\Encrypter;

/**
 * The APP_KEY line of the install pre-flight (`app:install`, which every deploy runs). A missing or malformed key
 * blocks; in production a key that is public or not random, or kept where it leaks easily, is a warning
 * (docs/devops/key-rotation.md).
 */
final class AppKeyCheck
{
    /**
     * SHA-256 of keys published in this repository: the placeholder the image build uses for `php artisan optimize`
     * (deploy/Dockerfile). tests/Feature/Core/AppKeyCheckTest keeps the list in step with the committed files.
     */
    public const PUBLIC_KEY_HASHES = [
        '66687aadf862bd776c8fc18b8e9f8e20089714856ee233b3902a591d0d5f2925',
    ];

    public function run(): EnvironmentCheck
    {
        $key = (string) config('app.key');
        $file = config('app.key_file');
        $file = is_string($file) ? $file : null;
        $fromFile = config('app.key_source') === 'file';

        if ($key === '') {
            return new EnvironmentCheck('APP_KEY', CheckStatus::Failed, $file !== null
                ? "APP_KEY_FILE is set to {$file}, which is missing, unreadable or empty."
                : 'APP_KEY is not set. Run `php artisan key:generate` once and keep the key: it decrypts stored secrets.');
        }

        $raw = self::raw($key);

        if (! Encrypter::supported($raw, (string) config('app.cipher'))) {
            return new EnvironmentCheck('APP_KEY', CheckStatus::Failed, 'APP_KEY does not fit the configured cipher. Generate one with `php artisan key:generate --show`.');
        }

        $problems = app()->isProduction() ? $this->productionProblems($key, $raw, $fromFile ? $file : null) : [];

        foreach ((array) config('app.previous_keys') as $previous) {
            if (! Encrypter::supported(self::raw((string) $previous), (string) config('app.cipher'))) {
                // Laravel refuses to build the encrypter then: nothing can be encrypted or decrypted.
                return new EnvironmentCheck('APP_KEY', CheckStatus::Failed, 'An entry of APP_PREVIOUS_KEYS does not fit the cipher, so the app cannot encrypt or decrypt anything. Fix or remove it.');
            }

            if (hash_equals($key, (string) $previous)) {
                $problems[] = 'APP_PREVIOUS_KEYS contains the current APP_KEY; list only the old keys.';
            }
        }

        if ($problems !== []) {
            return new EnvironmentCheck('APP_KEY', CheckStatus::Warning, implode(' ', array_unique($problems)));
        }

        $previous = count((array) config('app.previous_keys'));

        return new EnvironmentCheck('APP_KEY', CheckStatus::Ok, 'Set'.($fromFile ? ' (from APP_KEY_FILE)' : '')
            .($previous > 0 ? ", with {$previous} previous key(s): run `php artisan security:reencrypt`, then remove them (docs/devops/key-rotation.md)." : '.'));
    }

    /**
     * @return list<string>
     */
    private function productionProblems(string $key, string $raw, ?string $keyFile): array
    {
        $problems = [];

        if (in_array(hash('sha256', $raw), self::PUBLIC_KEY_HASHES, true) || $this->inExample($key)) {
            $problems[] = 'APP_KEY is a published key (the image build placeholder or the example file): anyone can decrypt the stored secrets. Rotate it (docs/devops/key-rotation.md).';
        } elseif (count(array_unique(str_split($raw))) < 16) {
            $problems[] = 'APP_KEY is not random. Generate one with `php artisan key:generate --show` and rotate it (docs/devops/key-rotation.md).';
        }

        if ($keyFile !== null && str_starts_with((string) realpath($keyFile), (string) realpath(base_path()).DIRECTORY_SEPARATOR)) {
            $problems[] = 'APP_KEY_FILE is inside the application directory, where code deploys, images and code backups pick it up. Keep it outside (e.g. /run/secrets).';
        }

        $dotenv = base_path('.env');

        if (is_file($dotenv) && (fileperms($dotenv) & 0o077) !== 0 && preg_match('/^APP_KEY=["\']?'.preg_quote($key, '/').'/m', (string) file_get_contents($dotenv)) === 1) {
            $problems[] = 'APP_KEY is read from .env, which other users on this server can read. Run `chmod 600 .env`, or inject the key from a secret store.';
        }

        return $problems;
    }

    /** Whether the key is the one an example environment file in the application ships with. */
    private function inExample(string $key): bool
    {
        foreach ([base_path('.env.example'), base_path('../deploy/.env.production.example')] as $example) {
            if (is_file($example) && preg_match('/^APP_KEY=["\']?(\S+?)["\']?$/m', (string) file_get_contents($example), $match) === 1 && hash_equals($match[1], $key)) {
                return true;
            }
        }

        return false;
    }

    private static function raw(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
