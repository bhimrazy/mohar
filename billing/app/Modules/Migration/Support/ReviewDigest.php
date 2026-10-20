<?php

namespace App\Modules\Migration\Support;

use App\Modules\Migration\Models\MigrationRun;

/**
 * What staff reviewed, as one hash: every row's decision, data checksum and matched client, under the run's current
 * check. Confirming sends the digest the page showed, and the import starts only while it still matches (the cPanel
 * import's rule), so a changed decision or a new check needs a new review. Reads plain columns only.
 */
final class ReviewDigest
{
    public static function of(MigrationRun $run): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, "run:{$run->id}|generation:{$run->generation}\n");

        foreach ($run->records()->orderBy('row_number')->select(['id', 'row_number', 'decision', 'checksum', 'match_client_id'])->lazy(5000) as $record) {
            hash_update($hash, "{$record->row_number}:{$record->decision}:{$record->checksum}:{$record->match_client_id}\n");
        }

        return hash_final($hash);
    }
}
