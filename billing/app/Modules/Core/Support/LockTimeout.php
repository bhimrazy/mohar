<?php

namespace App\Modules\Core\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * DDL on a busy PostgreSQL table that gives up instead of queueing (docs/architecture.md, "Migrations during
 * blue-green deploys").
 *
 * ALTER TABLE takes ACCESS EXCLUSIVE, even for a metadata-only change such as adding a nullable column. Behind any
 * open transaction that touched the table it waits in the lock queue, and every later read and write of the table
 * waits behind it. Here the statement waits at most $timeout for its locks. On a lock timeout the attempt is rolled
 * back, and retried after a doubling backoff; after the last attempt it throws, naming the table and the sessions
 * in the way. SQLite has no such lock queue: the DDL just runs.
 *
 *   LockTimeout::run('credit_ledger', fn () => Schema::table('credit_ledger', fn (Blueprint $table) => $table->string('note')->nullable()));
 *
 * By default each attempt runs in its own transaction (a savepoint when one is already open) under SET LOCAL, so the
 * timeout never outlives the attempt. A statement that cannot run in a transaction (CREATE INDEX CONCURRENTLY) passes
 * `transaction: false`: the timeout is then set for the session and restored afterwards. OnlineIndex does this.
 */
final class LockTimeout
{
    public const ATTEMPTS = 4;

    /** The wait before the second attempt, in milliseconds; it doubles before each later one. */
    public const BACKOFF_MS = 1000;

    /** SQLSTATE lock_not_available, raised when lock_timeout expires. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    /**
     * @template TResult
     *
     * @param  string  $table  the table the DDL locks, named in the failure message
     * @param  Closure(): TResult  $ddl  the statement(s); run again from the start on each attempt
     * @param  string|null  $timeout  a PostgreSQL duration in ms or s, such as "500ms" or "3s"; default
     *                                 `database.migrations.lock_timeout` (DB_MIGRATION_LOCK_TIMEOUT, 3s)
     * @return TResult
     */
    public static function run(string $table, Closure $ddl, ?string $timeout = null, int $attempts = self::ATTEMPTS, bool $transaction = true): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $ddl();
        }

        $timeout ??= (string) config('database.migrations.lock_timeout', '3s');

        if (preg_match('/^[1-9]\d{0,6}(ms|s)$/', $timeout) !== 1 || $attempts < 1) {
            throw new LogicException("LockTimeout needs a duration such as 3s or 500ms and at least one attempt, not {$timeout} and {$attempts}.");
        }

        if (! $transaction && $connection->transactionLevel() > 0) {
            throw new LogicException("DDL on {$table} without a transaction must run outside one: set `public \$withinTransaction = false;` on the migration.");
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $transaction ? self::inTransaction($ddl, $timeout) : self::inSession($ddl, $timeout);
            } catch (QueryException $exception) {
                // Unlike portability rule 7, retrying is safe here: the failed attempt was rolled back first.
                if (($exception->errorInfo[0] ?? $exception->getCode()) !== self::LOCK_NOT_AVAILABLE) {
                    throw $exception;
                }

                if ($attempt >= $attempts) {
                    throw new RuntimeException(self::failure($table, $timeout, $attempts), 0, $exception);
                }

                $wait = self::BACKOFF_MS * 2 ** ($attempt - 1);
                Log::warning('DDL lock wait timed out; retrying', ['table' => $table, 'timeout' => $timeout, 'attempt' => $attempt, 'of' => $attempts, 'retry_in_ms' => $wait]);
                Sleep::for($wait)->milliseconds();
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $ddl
     * @return TResult
     */
    private static function inTransaction(Closure $ddl, string $timeout): mixed
    {
        $previous = self::current();
        DB::beginTransaction();

        try {
            DB::select("select set_config('lock_timeout', ?, true)", [$timeout]);
            $result = $ddl();
            // Inside a savepoint SET LOCAL would last until the outer transaction ends; put it back for what follows.
            DB::select("select set_config('lock_timeout', ?, true)", [$previous]);
            DB::commit();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $ddl
     * @return TResult
     */
    private static function inSession(Closure $ddl, string $timeout): mixed
    {
        $previous = self::current();
        DB::select("select set_config('lock_timeout', ?, false)", [$timeout]);

        try {
            return $ddl();
        } finally {
            DB::select("select set_config('lock_timeout', ?, false)", [$previous]);
        }
    }

    private static function current(): string
    {
        return (string) DB::selectOne("select current_setting('lock_timeout') as value")?->value;
    }

    /**
     * Who was in the way: the sessions holding a lock on the table, or, when none does (a concurrent index build
     * also waits for every older transaction), the oldest open transactions. No query text: it may carry personal data.
     */
    private static function failure(string $table, string $timeout, int $attempts): string
    {
        $sessions = DB::select(
            "select a.pid, a.state, a.application_name, string_agg(distinct l.mode, '/') as modes,
                coalesce(extract(epoch from (now() - a.xact_start))::bigint, 0) as seconds
             from pg_locks l join pg_stat_activity a on a.pid = l.pid
             where l.locktype = 'relation' and l.relation = to_regclass(?) and l.granted and l.pid <> pg_backend_pid()
             group by a.pid, a.state, a.application_name, a.xact_start
             order by a.xact_start nulls last limit 5",
            [DB::connection()->getTablePrefix().$table],
        );
        $label = "sessions holding a lock on {$table}";

        if ($sessions === []) {
            $sessions = DB::select(
                'select a.pid, a.state, a.application_name, null as modes, extract(epoch from (now() - a.xact_start))::bigint as seconds
                 from pg_stat_activity a
                 where a.datname = current_database() and a.xact_start is not null and a.pid <> pg_backend_pid()
                 order by a.xact_start limit 3',
            );
            $label = 'no session holds a lock on the table now; the oldest open transactions';
        }

        $described = array_map(fn (object $session): string => sprintf(
            'pid %d (%s for %ds%s, application "%s")',
            $session->pid,
            $session->state ?? 'unknown state',
            $session->seconds,
            $session->modes !== null ? ', holds '.$session->modes : '',
            $session->application_name,
        ), $sessions);

        return sprintf(
            'DDL on table %s did not get its lock within %s in %d attempts, so it stopped instead of queueing in front of '
            .'the table\'s reads and writes. In the way: %s. Let that transaction finish (or end it with '
            .'pg_terminate_backend(pid)) and run the migration again.',
            $table, $timeout, $attempts, $label.': '.($described === [] ? 'none found' : implode('; ', $described)),
        );
    }
}
