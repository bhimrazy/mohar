<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\DB;

/**
 * Which identity image paths are still referred to, by path alone on any client: a document's front or back, or either
 * side of a half-finished re-encode (its rewrite journal, settled by StoredImageReencoder). A file `security:reencrypt`
 * wrote but had not switched a row to yet is old only if that run was killed; its own settle() deletes whichever file
 * the row does not point at, so listing it here is harmless.
 */
class OrphanFiles
{
    /** Only files named kyc/<client id>/<name> are ever candidates; the id is the first group. */
    public const PATTERN = '#^kyc/(\d+)/[^/]+$#';

    /**
     * The given paths that something still refers to. The path columns have no index: this runs once per check over
     * at most kyc.orphan_scan_limit paths, and per file when staff delete.
     *
     * @param  list<string>  $paths
     * @return array<string, true>
     */
    public function referenced(array $paths): array
    {
        $referenced = [];

        foreach (array_chunk($paths, 500) as $chunk) {
            foreach (KycDocument::query()->whereIn('front_path', $chunk)->orWhereIn('back_path', $chunk)->get(['id', 'front_path', 'back_path']) as $document) {
                foreach ($document->paths() as $path) {
                    $referenced[$path] = true;
                }
            }

            foreach (DB::table(StoredImageReencoder::REWRITES)->whereIn('old_path', $chunk)->orWhereIn('new_path', $chunk)->get(['old_path', 'new_path']) as $row) {
                $referenced[(string) $row->old_path] = true;
                $referenced[(string) $row->new_path] = true;
            }
        }

        return $referenced;
    }

    /** The earliest modification time a file may have to be a candidate (kyc.orphan_min_age_hours, at least 24). */
    public function olderThan(): int
    {
        return now()->subHours(max(24, (int) config('kyc.orphan_min_age_hours', 24)))->getTimestamp();
    }
}
