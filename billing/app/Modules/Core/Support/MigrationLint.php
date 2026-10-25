<?php

namespace App\Modules\Core\Support;

use PhpToken;

/**
 * The static checks behind `deploy:check-migrations` (docs/architecture.md, "Migrations during blue-green deploys").
 *
 * A migration is read as tokens, split into statements per method (every method except down()), and each statement
 * is matched against the rules. Findings come in three levels:
 *  - `excused`: a change the previous release may not survive (drop, rename, type change, tightened constraint). A
 *    `@contract` or `@compatible` tag with a reason, in a comment directly before the statement, excuses that
 *    statement only. A tag elsewhere in the file (the class docblock) excuses the file's only such statement.
 *  - `error`: rules 5 and 6, which no tag excuses: an ALTER outside LockTimeout::run, an index outside OnlineIndex,
 *    OnlineIndex in a migration that runs in a transaction.
 *  - `warning`: advice only.
 *
 * It is a lint, not a parser of PHP semantics: a table name or SQL built at run time is not seen.
 */
final class MigrationLint
{
    /**
     * Timestamp prefixes that already appear on two merged migrations. Renaming a merged migration would run it again
     * on databases that have it, so these stay; any other shared prefix is refused.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_DUPLICATE_TIMESTAMPS = [
        '2026_10_22_980300' => ['2026_10_22_980300_create_migration_invitations_table', '2026_10_22_980300_seed_email_hash_secret'],
        '2026_10_24_000100' => ['2026_10_24_000100_add_failed_at_index_to_failed_jobs', '2026_10_24_000100_add_password_expires_at_to_users'],
        '2026_10_26_300720' => ['2026_10_26_300720_add_note_settlement_and_reversal_columns', '2026_10_26_300720_add_register_indexes_to_invoices'],
    ];

    /** @var array<string, string> pattern => what it does; applied to the code of a statement, comments removed */
    private const DESTRUCTIVE = [
        '/->\s*drop(Column|Columns|Morphs|NullableMorphs|Timestamps|TimestampsTz|SoftDeletes|SoftDeletesTz|RememberToken)\s*\(/' => 'drops a column',
        '/->\s*renameColumn\s*\(/' => 'renames a column',
        '/->\s*change\s*\(\s*\)/' => 'changes a column definition',
        '/\bSchema\s*::\s*(drop|dropIfExists|dropColumns|dropAllTables|dropAllViews|dropAllTypes)\s*\(/' => 'drops a table or columns',
        '/->\s*(drop|dropIfExists|dropColumns|dropAllTables|dropAllViews|dropAllTypes)\s*\(/' => 'drops a table or columns',
        '/\bSchema\s*::\s*rename\s*\(/' => 'renames a table',
        '/->\s*rename\s*\(/' => 'renames a table',
    ];

    /** @var array<string, string> pattern => what it does; applied to a statement that runs raw SQL */
    private const RAW_SQL = [
        '/\b(DROP|RENAME)\b/i' => 'runs raw SQL that drops or renames',
        '/\bALTER\s+(?:COLUMN\s+)?\S+\s+(?:SET\s+DATA\s+)?TYPE\b/i' => 'changes a column type',
        '/\bSET\s+NOT\s+NULL\b/i' => 'makes a column NOT NULL',
        '/\bADD\s+(?:CONSTRAINT|CHECK|UNIQUE|PRIMARY\s+KEY|FOREIGN\s+KEY)\b/i' => 'adds a constraint the previous release may violate',
    ];

    private const TIGHTENED_KEY = 'tightens a foreign key (dropForeign, then a stricter one)';

    private const UNIQUE = 'adds a unique index the previous release may violate';

    private const SCHEMA_TABLE = '/\bSchema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?table\s*\(\s*(?:([\'"])(\w+)\1)?/';

    private const RAW_CALL = '/\b(?:statement|unprepared)\s*\(/i';

    /**
     * @return list<array{message: string, line: int, level: 'error'|'excused'|'warning', tag: 'contract'|'compatible'|null}>
     */
    public static function analyse(string $source): array
    {
        [$fileComments, $methods] = self::parse($source);

        $all = array_merge(...array_values($methods)) ?: [];
        $text = implode("\n", array_column($all, 'text'));
        $created = self::createdTables($text);
        $covered = self::coveredMethods($all);
        $hasNoTransaction = preg_match('/\$withinTransaction\s*=\s*false\b/', $text.' '.self::codeOf($source)) === 1;

        $findings = [];
        $pending = [];

        foreach ($methods as $method => $statements) {
            $tightened = self::tightensKey($statements);

            foreach ($statements as $statement) {
                $s = $statement['text'];
                $excusable = [];

                foreach (self::DESTRUCTIVE as $pattern => $meaning) {
                    if (preg_match($pattern, $s) === 1) {
                        $excusable[] = $meaning;
                    }
                }

                $raw = preg_match(self::RAW_CALL, $s) === 1;

                if ($raw) {
                    foreach (self::RAW_SQL as $pattern => $meaning) {
                        if (preg_match($pattern, $s) === 1) {
                            $excusable[] = $meaning;
                        }
                    }
                }

                if ($tightened && preg_match('/->\s*dropForeign\s*\(/', $s) === 1) {
                    $excusable[] = self::TIGHTENED_KEY;
                }

                $existing = self::existingTables($s, $raw, $created);
                $errors = [];

                if (preg_match('/\bOnlineIndex\s*::\s*(create|trigram)\b/', $s) === 1) {
                    if (preg_match('/\bunique\s*:\s*true\b/', $s) === 1 && ! self::onCreatedTable($s, $created)) {
                        $excusable[] = self::UNIQUE;
                    }
                }

                if ($existing !== [] && preg_match('/->\s*unique\s*\(/', $s) === 1) {
                    $excusable[] = self::UNIQUE;
                }

                if ($existing !== [] && preg_match('/\bLockTimeout\s*::\s*run\s*\(/', $s) !== 1 && ! isset($covered[$method])) {
                    $errors[] = 'alters an existing table outside LockTimeout::run (architecture rule 5)';
                }

                $rawIndex = $raw && preg_match('/\bCREATE\s+(UNIQUE\s+)?INDEX\b(?:\s+CONCURRENTLY)?(?:\s+IF\s+NOT\s+EXISTS)?\s+\S+\s+ON\s+(?:ONLY\s+)?["`]?(\w+)/i', $s, $index) === 1
                    && ! in_array($index[2], $created, true);

                if ($rawIndex && $index[1] !== '') {
                    $excusable[] = self::UNIQUE;
                }

                if ($rawIndex || ($existing !== [] && preg_match('/->\s*(index|unique|fullText|spatialIndex|rawIndex)\s*\(/', $s) === 1)) {
                    $errors[] = 'builds an index outside OnlineIndex (architecture rule 6)';
                }

                if (! $hasNoTransaction && preg_match('/\bOnlineIndex\s*::\s*(create|trigram)\b/', $s) === 1
                    && preg_match('/\ballowLocking\s*:\s*true\b/', $text) !== 1) {
                    $errors[] = 'OnlineIndex without `public $withinTransaction = false;` (architecture rule 6)';
                }

                foreach (array_unique($errors) as $message) {
                    $findings[] = ['message' => $message, 'line' => $statement['line'], 'level' => 'error', 'tag' => null];
                }

                if ($excusable !== []) {
                    $pending[] = ['statement' => $statement, 'messages' => array_values(array_unique($excusable))];
                }
            }
        }

        $fileTag = self::tag($fileComments);
        $untagged = count(array_filter($pending, fn (array $item): bool => self::tag($item['statement']['comments']) === null));

        foreach ($pending as $item) {
            $tag = self::tag($item['statement']['comments']) ?? ($untagged === 1 ? $fileTag : null);

            foreach ($item['messages'] as $message) {
                $findings[] = ['message' => $message, 'line' => $item['statement']['line'], 'level' => $tag === null ? 'error' : 'excused', 'tag' => $tag];
            }
        }

        if (! $hasNoTransaction) {
            preg_match_all('/\bLockTimeout\s*::\s*run\s*\(\s*[\'"](\w+)[\'"]/', $text, $tables);

            if (count(array_unique($tables[1])) > 1) {
                $findings[] = ['message' => 'LockTimeout::run on several tables in one transaction: set `public $withinTransaction = false;` so locks of earlier tables are not held through a later backoff (architecture rule 5)', 'line' => 1, 'level' => 'warning', 'tag' => null];
            }
        }

        usort($findings, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * A message per timestamp prefix that two migrations share, among the checked files and every known migration.
     *
     * @param  list<string>  $checked  paths
     * @param  list<string>  $known  paths of every migration the application has
     * @return list<string>
     */
    public static function duplicateTimestamps(array $checked, array $known): array
    {
        $names = [];

        foreach (array_merge($checked, $known) as $path) {
            $name = basename($path, '.php');

            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $match) === 1) {
                $names[$match[1]][$name] = true;
            }
        }

        $messages = [];

        foreach ($checked as $path) {
            $name = basename($path, '.php');

            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $match) !== 1 || count($names[$match[1]]) < 2) {
                continue;
            }

            $group = array_keys($names[$match[1]]);
            sort($group);
            $allowed = self::ALLOWED_DUPLICATE_TIMESTAMPS[$match[1]] ?? null;

            if ($allowed === $group) {
                continue;
            }

            $messages[$match[1]] = "{$match[1]} is used by ".implode(' and ', $group).'; give the new one a timestamp of its own.';
        }

        return array_values($messages);
    }

    /**
     * @return array{0: list<string>, 1: array<string, list<array{text: string, line: int, comments: list<string>}>>}
     */
    private static function parse(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $fileComments = [];
        $classAt = null;

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id === T_CLASS && self::previous($tokens, $i) !== '::') {
                $classAt = $i;

                break;
            }

            if (self::isComment($tokens[$i])) {
                $fileComments[] = $tokens[$i]->text;
            }
        }

        if ($classAt === null) {
            return [[], ['' => self::statements($tokens)]];
        }

        $open = $classAt;

        while ($open < $count && $tokens[$open]->text !== '{') {
            $open++;
        }

        $close = self::close($tokens, $open);
        $methods = [];

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if (self::isComment($token)) {
                $fileComments[] = $token->text;

                continue;
            }

            if ($token->id !== T_FUNCTION) {
                continue;
            }

            $name = $i + 1;

            while ($name < $close && ($tokens[$name]->isIgnorable() || $tokens[$name]->text === '&')) {
                $name++;
            }

            $body = $name;
            $depth = 0;

            for (; $body < $close; $body++) {
                $depth += match ($tokens[$body]->text) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };

                if ($depth === 0 && in_array($tokens[$body]->text, ['{', ';'], true)) {
                    break;
                }
            }

            if (($tokens[$body]->text ?? '') !== '{') {
                $i = $body;

                continue;
            }

            $end = self::close($tokens, $body);
            $methodName = strtolower($tokens[$name]->text);

            if ($methodName !== 'down') {
                $methods[$methodName] = self::statements(array_slice($tokens, $body + 1, $end - $body - 1));
            }

            $i = $end;
        }

        return [$fileComments, $methods];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return list<array{text: string, line: int, comments: list<string>}>
     */
    private static function statements(array $tokens): array
    {
        $statements = [];
        $pending = [];
        $current = null;
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_WHITESPACE) {
                if ($current !== null) {
                    $current['text'] .= ' ';
                }

                continue;
            }

            if (self::isComment($token)) {
                if ($current === null) {
                    $pending[] = $token->text;
                } else {
                    $current['comments'][] = $token->text;
                }

                continue;
            }

            $current ??= ['text' => '', 'line' => $token->line, 'comments' => array_splice($pending, 0)];
            $current['text'] .= $token->text;

            $depth += match ($token->text) {
                '(', '[', '{', '${' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };

            $ends = ($token->text === ';' && $depth === 0)
                || ($token->text === '}' && $depth === 0
                    && ! in_array(strtolower(self::next($tokens, $i)), ['else', 'elseif', 'catch', 'finally', ';', ')', ',', '->', '?->', '.', ']', '?', ':'], true));

            if ($ends) {
                $statements[] = $current;
                $current = null;
            }
        }

        if ($current !== null) {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function close(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $depth += match ($tokens[$i]->text) {
                '{', '${' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function previous(array $tokens, int $at): string
    {
        for ($i = $at - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i]->text;
            }
        }

        return '';
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function next(array $tokens, int $at): string
    {
        $count = count($tokens);

        for ($i = $at + 1; $i < $count; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i]->text;
            }
        }

        return '';
    }

    private static function isComment(PhpToken $token): bool
    {
        return $token->id === T_COMMENT || $token->id === T_DOC_COMMENT;
    }

    private static function codeOf(string $source): string
    {
        return implode('', array_map(fn (PhpToken $token): string => self::isComment($token) ? '' : $token->text, PhpToken::tokenize($source)));
    }

    /**
     * @param  list<string>  $comments
     * @return 'contract'|'compatible'|null
     */
    private static function tag(array $comments): ?string
    {
        foreach ($comments as $comment) {
            if (preg_match('/@(contract|compatible)\b([^@]*)/', $comment, $match) !== 1) {
                continue;
            }

            $reason = preg_replace('/^\s*\*+|\*+\/$/m', ' ', $match[2]) ?? '';

            // A tag without a reason is no tag: the reason is what a reviewer checks.
            if (count(preg_split('/\s+/', trim($reason), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 3) {
                return $match[1] === 'contract' ? 'contract' : 'compatible';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function createdTables(string $text): array
    {
        preg_match_all('/\bSchema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?create\s*\(\s*[\'"](\w+)[\'"]/', $text, $schema);
        preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?(\w+)/i', $text, $raw);

        return array_values(array_unique(array_merge($schema[1], $raw[1])));
    }

    /**
     * @param  list<string>  $created
     * @return list<string> tables the statement alters that this migration did not create; "?" when the name is not a literal
     */
    private static function existingTables(string $s, bool $raw, array $created): array
    {
        $tables = [];

        if (preg_match_all(self::SCHEMA_TABLE, $s, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $tables[] = ($match[2] ?? '') !== '' ? $match[2] : '?';
            }
        }

        if ($raw && preg_match_all('/\bALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?["`]?(\w+)/i', $s, $alter) > 0) {
            $tables = array_merge($tables, $alter[1]);
        }

        return array_values(array_diff($tables, $created));
    }

    /**
     * @param  list<string>  $created
     */
    private static function onCreatedTable(string $s, array $created): bool
    {
        return preg_match('/OnlineIndex\s*::\s*create\s*\(\s*[\'"](\w+)[\'"]/', $s, $match) === 1 && in_array($match[1], $created, true);
    }

    /**
     * Methods that only run inside a LockTimeout::run call: `LockTimeout::run('t', fn () => $this->alter())`.
     *
     * @param  list<array{text: string, line: int, comments: list<string>}>  $statements
     * @return array<string, true>
     */
    private static function coveredMethods(array $statements): array
    {
        $covered = [];

        foreach ($statements as $statement) {
            if (preg_match('/\bLockTimeout\s*::\s*run\s*\(/', $statement['text']) !== 1) {
                continue;
            }

            preg_match_all('/(?:\$this\s*->|(?:self|static)\s*::)\s*(\w+)\s*\(/', $statement['text'], $calls);

            foreach ($calls[1] as $call) {
                $covered[strtolower($call)] = true;
            }
        }

        return $covered;
    }

    /**
     * A foreign key dropped and added again within a method, where the new one has no ON DELETE / ON UPDATE action
     * (which is NO ACTION) or names a restrictive one: the previous release's deletes and writes can start to fail.
     *
     * @param  list<array{text: string, line: int, comments: list<string>}>  $statements
     */
    private static function tightensKey(array $statements): bool
    {
        $drops = array_filter($statements, fn (array $s): bool => preg_match('/->\s*dropForeign\s*\(/', $s['text']) === 1);
        $adds = array_filter($statements, fn (array $s): bool => preg_match('/->\s*(foreign|constrained)\s*\(|\bADD\s+(?:CONSTRAINT\s+\S+\s+)?FOREIGN\s+KEY\b/i', $s['text']) === 1);

        if ($drops === [] || $adds === []) {
            return false;
        }

        foreach ($adds as $add) {
            $t = $add['text'];

            $restrictive = preg_match('/restrictOn(Delete|Update)|noActionOn(Delete|Update)|\bon(Delete|Update)\s*\(\s*[\'"](restrict|no action)[\'"]|ON\s+(DELETE|UPDATE)\s+(RESTRICT|NO\s+ACTION)/i', $t) === 1;
            $unspecified = preg_match('/(null|cascade)On(Delete|Update)|\bon(Delete|Update)\s*\(|ON\s+(DELETE|UPDATE)\b/i', $t) !== 1;

            if ($restrictive || $unspecified) {
                return true;
            }
        }

        return false;
    }
}
