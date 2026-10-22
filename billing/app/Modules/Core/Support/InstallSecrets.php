<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\InstallSecret;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Secrets that stay the same across APP_KEY rotations, for keyed hashes that are stored and looked up later (an
 * HMAC keyed from APP_KEY itself would change with every rotation). Created on first use, encrypted at rest, and
 * memoized for the process.
 */
class InstallSecrets
{
    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param  Closure(): string  $initial  the value to store when the secret does not exist yet
     */
    public function get(string $name, Closure $initial): string
    {
        if (isset($this->values[$name])) {
            return $this->values[$name];
        }

        $stored = InstallSecret::query()->where('name', $name)->first();

        if ($stored === null) {
            // Two first uses at once: one insert wins, both read it back.
            DB::table('install_secrets')->insertOrIgnore(['name' => $name, 'value' => Crypt::encryptString($initial()), 'created_at' => now(), 'updated_at' => now()]);
            $stored = InstallSecret::query()->where('name', $name)->firstOrFail();
        }

        return $this->values[$name] = $stored->value;
    }
}
