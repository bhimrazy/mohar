<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\InstallSecret;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Secrets that stay the same across APP_KEY rotations, for keyed hashes that are stored and looked up later (an
 * HMAC keyed from APP_KEY itself would change with every rotation). The owning module defines each secret's initial
 * value; it is created on first use (or by seedMissing(), which `security:reencrypt` calls first), encrypted at rest
 * and memoized for the process.
 */
class InstallSecrets
{
    /**
     * Definitions are per process, not per instance: modules define them at boot, and a fresh instance (a new scope
     * in a long-running worker) must still know them.
     *
     * @var array<string, Closure(list<string>): string>
     */
    private static array $definitions = [];

    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param  Closure(list<string>): string  $initial  given the configured keys (current first, then the previous
     *                                                  ones), the value to store when the secret does not exist yet
     */
    public function define(string $name, Closure $initial): void
    {
        self::$definitions[$name] = $initial;
    }

    public function get(string $name): string
    {
        if (isset($this->values[$name])) {
            return $this->values[$name];
        }

        $stored = InstallSecret::query()->where('name', $name)->first();

        if ($stored === null) {
            $initial = self::$definitions[$name] ?? throw new LogicException("No install secret is defined as [{$name}].");
            $keys = array_values(array_filter([(string) config('app.key'), ...array_map('strval', (array) config('app.previous_keys'))]));
            // Two first uses at once: one insert wins, both read it back.
            DB::table('install_secrets')->insertOrIgnore(['name' => $name, 'value' => Crypt::encryptString($initial($keys)), 'created_at' => now(), 'updated_at' => now()]);
            $stored = InstallSecret::query()->where('name', $name)->firstOrFail();
        }

        return $this->values[$name] = $stored->value;
    }

    /**
     * Create every defined secret that does not exist yet.
     */
    public function seedMissing(): void
    {
        foreach (array_keys(self::$definitions) as $name) {
            $this->get($name);
        }
    }
}
