<?php declare(strict_types=1);

namespace BackofficeMcp;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

/**
 * Runs queries under a time limit per statement, so that a badly filtered one fails instead of
 * holding a production database connection open.
 *
 * MySQL, MariaDB and PostgreSQL only. SQLite and SQL Server have no session setting for it, and
 * run without a limit.
 */
final class QueryTimeout
{
    /**
     * @template T
     * @param  int  $milliseconds  per statement, 0 meaning no limit
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Connection $connection, int $milliseconds, Closure $callback): mixed
    {
        [$set, $reset] = match (true) {
            // Before MySQL, which it extends
            $connection instanceof MariaDbConnection => [
                sprintf('SET SESSION max_statement_time = %F', $milliseconds / 1000),
                'SET SESSION max_statement_time = DEFAULT',
            ],
            $connection instanceof MySqlConnection => [
                sprintf('SET SESSION max_execution_time = %d', $milliseconds),
                'SET SESSION max_execution_time = DEFAULT',
            ],
            $connection instanceof PostgresConnection => [
                sprintf('SET statement_timeout = %d', $milliseconds),
                'RESET statement_timeout',
            ],
            default => [null, null],
        };

        if ($set === null || $reset === null) {
            return $callback();
        }

        $connection->statement($set);

        try {
            return $callback();
        } finally {
            // Back to the server's default, since the connection may outlive this request
            // (Octane, Lambda, queue workers)
            $connection->statement($reset);
        }
    }

    /**
     * Whether the database cancelled this query for running past the limit.
     */
    public static function cancelled(QueryException $e): bool
    {
        return match (true) {
            // ER_QUERY_TIMEOUT (MySQL) and ER_STATEMENT_TIMEOUT (MariaDB)
            in_array($e->errorInfo[1] ?? null, [3024, 1969], true) => true,
            // query_canceled
            ($e->errorInfo[0] ?? null) === '57014' => true,
            default => false,
        };
    }
}
