<?php

namespace App\Modules\Migration\Support;

use App\Modules\Migration\Models\MigrationRun;

/**
 * What staff reviewed, as one hash (client-import spec §5.6). Each row stores a hash of its decision, data checksum,
 * matched client and login choice under the run's current check; the run's digest combines them (XOR), so a single
 * decision updates it in constant time, without reading or decrypting other rows. Confirming sends the digest the page
 * showed, and the import starts only while it still matches.
 */
final class ReviewDigest
{
    public static function row(MigrationRun $run, int $rowNumber, ?string $decision, ?string $checksum, ?int $matchClientId, ?bool $linkLogin): string
    {
        return hash('sha256', implode('|', [$run->id, $run->generation, $rowNumber, $decision, $checksum, $matchClientId, var_export($linkLogin, true)]));
    }

    /**
     * The digest over every row's stored hash.
     */
    public static function of(MigrationRun $run): string
    {
        $digest = self::bytes(null);

        foreach ($run->records()->select(['id', 'review_hash'])->lazyById(5000) as $record) {
            $digest ^= self::bytes($record->review_hash);
        }

        return bin2hex($digest);
    }

    /**
     * The digest after one row's hash changed from $old to $new.
     */
    public static function replace(?string $digest, ?string $old, ?string $new): string
    {
        return bin2hex(self::bytes($digest) ^ self::bytes($old) ^ self::bytes($new));
    }

    /**
     * 32 bytes, always: a missing hash counts as zeros, so it never shortens the XOR.
     */
    private static function bytes(?string $hex): string
    {
        $bytes = $hex === null ? false : hex2bin($hex);

        return $bytes !== false && strlen($bytes) === 32 ? $bytes : str_repeat("\0", 32);
    }
}
