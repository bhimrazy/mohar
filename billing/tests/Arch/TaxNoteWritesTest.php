<?php

/*
 * Filed notes are immutable (ADR 0024 §1, §9): the models refuse changes and deletes, but a query-builder write skips
 * model events. There is no database trigger in v1, so this scan keeps every such write out of the code: notes and their
 * lines are only created through Eloquent `create()` / `save()` of a new row. Migrations are not scanned. It is a tripwire
 * for the usual forms, not a guarantee: a write assembled from strings at run time would pass it.
 */

const NOTE_WRITE_FORMS = [
    'the query builder on a note table' => '/\bDB\s*::\s*table\s*\(\s*[\'"]tax_note(s|_lines)\b/',
    'raw SQL that writes a note table' => '/\b(update|insert\s+into|delete\s+from|truncate(\s+table)?)\s+["`]?tax_note(s|_lines)\b/i',
    'a bulk or quiet write through a note model' => '/\bTaxNote(Line)?\s*::[^;]*->\s*(update|delete|forceDelete|insert|insertOrIgnore|upsert|truncate|increment|decrement|saveQuietly|updateQuietly|deleteQuietly)\s*\(/s',
    'events switched off around a note model' => '/\bTaxNote(Line)?\s*::\s*withoutEvents\b/',
    'a note query builder kept in a variable' => '/=\s*TaxNote(Line)?\s*::\s*(query|where\w*)\s*\(/',
];

/**
 * @return array<string, string> label => file, for each write found in the code (comments left out)
 */
function noteWritesIn(string $code, string $file): array
{
    $found = [];
    $plain = collect(token_get_all($code))->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token) => is_array($token) ? $token[1] : $token)->implode('');

    foreach (NOTE_WRITE_FORMS as $label => $pattern) {
        if (preg_match($pattern, $plain) === 1) {
            $found[$label] = $file;
        }
    }

    return $found;
}

test('no code writes to the note tables except by creating a model', function () {
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/Migrations/')) {
            $found += array_map(fn (string $path): string => $path, noteWritesIn((string) file_get_contents($file->getPathname()), $file->getPathname()));
        }
    }

    expect($found)->toBe([]);
});

test('the scan recognises the writes it forbids', function (string $code) {
    expect(noteWritesIn("<?php {$code}", 'x'))->not->toBe([]);
})->with([
    "DB::table('tax_notes')->update(['total' => 1]);",
    'DB::table("tax_note_lines")->delete();',
    'TaxNote::query()->where("id", 1)->update(["total" => 1]);',
    'TaxNoteLine::where("tax_note_id", 1)->delete();',
    "DB::table('tax_notes as n')->where('n.id', 1)->update(['total' => 1]);",
    'DB::statement("update tax_notes set total = 1");',
    'TaxNote::query()->first()->saveQuietly();',
    'TaxNote::withoutEvents(fn () => 1);',
    '$notes = TaxNote::query()->where("id", 1);',
]);

afterAll(fn () => releaseArchCache());
