<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoint behind the admin command palette. Groups are filtered by the staff member's permissions.
 */
class SearchController extends Controller
{
    public const MIN_LENGTH = 2;

    public const PER_GROUP = 5;

    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        // "%" would turn the search into a match-all (portable LIKE has no common escape character).
        $term = trim(str_replace(['%', '\\'], '', $request->string('q')->toString()));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json(['groups' => []]);
        }

        $term = mb_substr($term, 0, 100);

        $groups = array_map(fn (array $group): array => [
            'group' => $group['group'],
            'results' => array_slice($group['results'], 0, self::PER_GROUP),
        ], $search->search($term, $request->user()));

        return response()->json(['groups' => $groups]);
    }
}
