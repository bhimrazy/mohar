<?php

namespace App\Modules\Core\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Case-insensitive LIKE with the user's term treated literally: "%" and "_" in the term are escaped with
 * "!" and the statement declares ESCAPE '!', which SQLite, PostgreSQL and MySQL all honour. Use this for
 * every search box and filter so a term such as "%%" or "__" cannot match everything.
 *
 *   Like::where($query, 'email', Like::contains($term));        // %term%
 *   Like::where($query, 'name', Like::startsWith($term), 'or'); // term%
 */
final class Like
{
    public static function escape(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }

    /**
     * @param  string  $column  a trusted column name (never user input)
     * @param  string  $pattern  built with contains() / startsWith()
     */
    public static function where(Builder $query, string $column, string $pattern, string $boolean = 'and'): void
    {
        $wrapped = $query->getGrammar()->wrap($column);

        $query->whereRaw("lower({$wrapped}) like ? escape '!'", [mb_strtolower($pattern)], $boolean);
    }
}
