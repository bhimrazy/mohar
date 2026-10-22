<?php

namespace App\Support\Security;

/**
 * Reads a secret from the environment, or from the file a `<NAME>_FILE` variable points at (Docker and Compose
 * secrets under /run/secrets, systemd credentials, Kubernetes secret volumes). The variable itself wins when both
 * are set. Used by config files only, so it runs when the configuration is loaded or cached, never per request.
 */
final class SecretEnv
{
    public static function get(string $name): ?string
    {
        $value = env($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $file = self::file($name);

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = trim((string) file_get_contents($file));

        return $contents === '' ? null : $contents;
    }

    /** The path in `<NAME>_FILE`, or null when it is not set. */
    public static function file(string $name): ?string
    {
        $file = env($name.'_FILE');

        return is_string($file) && $file !== '' ? $file : null;
    }
}
