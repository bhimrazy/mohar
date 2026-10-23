<?php

namespace App\Support\Settings;

use Closure;

/**
 * Settings that a module fixes for a while (for example numbering once tax documents are issued under it). The settings
 * form shows a locked field disabled, with the reason, and the save refuses to change it.
 *
 *   app(SettingLocks::class)->lock(['billing.number_format'], fn (): ?string => $on ? 'Fixed while … is on.' : null, guard: 'billing.nepal_vat.enabled');
 *
 * `$reason` is called per check and returns text while the keys are locked, null otherwise; it must read the state
 * from the database. `$guard` names a settings row: the save holds a shared lock on it while it checks, so the module
 * can take the same row `FOR UPDATE` to switch the lock on and no save slips past the switch. The module creates the row
 * (the save only locks it, and does nothing when it is missing); a row that reads the same as "unset" is fine.
 * Reasons are remembered until `flush()`, so one page asks the database once per lock.
 */
class SettingLocks
{
    /** @var list<array{keys: list<string>, reason: Closure(): ?string, guard: ?string}> */
    private array $locks = [];

    /** @var array<int, string|null> lock index => reason, until flush() */
    private array $known = [];

    /**
     * @param  list<string>  $keys
     * @param  Closure(): ?string  $reason
     */
    public function lock(array $keys, Closure $reason, ?string $guard = null): void
    {
        $this->locks[] = ['keys' => $keys, 'reason' => $reason, 'guard' => $guard];
    }

    /** Why a key is fixed right now, or null when it can be changed. */
    public function reason(string $key): ?string
    {
        foreach ($this->locks as $index => $lock) {
            if (! in_array($key, $lock['keys'], true)) {
                continue;
            }

            $reason = array_key_exists($index, $this->known) ? $this->known[$index] : ($this->known[$index] = ($lock['reason'])());

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** Forget the remembered reasons: call it before a check that must see the current state. */
    public function flush(): void
    {
        $this->known = [];
    }

    /**
     * The guard rows of the locks that cover any of these keys.
     *
     * @internal for `UpdateSettings`
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function guards(array $keys): array
    {
        $guards = [];

        foreach ($this->locks as $lock) {
            if ($lock['guard'] !== null && array_intersect($lock['keys'], $keys) !== []) {
                $guards[] = $lock['guard'];
            }
        }

        return array_values(array_unique($guards));
    }
}
