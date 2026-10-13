<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use BackofficeMcp\QueryTimeout;

/**
 * Against a real database server only: run the suite with `DB_CONNECTION=mysql|mariadb|pgsql`.
 */
class DatabaseTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no statement timeout.');
        }
    }

    public function test_a_slow_query_is_cancelled_and_recognised_as_such(): void
    {
        try {
            QueryTimeout::run(DB::connection(), 100, fn() => DB::select($this->slowQuery()));
            $this->fail('The query should have been cancelled.');
        } catch (QueryException $e) {
            $this->assertTrue(QueryTimeout::cancelled($e), 'Not recognised as a timeout: ' . $e->getMessage());
        }
    }

    public function test_the_session_is_back_to_its_default_afterwards(): void
    {
        $before = $this->currentTimeout();

        QueryTimeout::run(DB::connection(), 1234, function () {
            $this->assertNotEquals($this->currentTimeout(), DB::connection()->getDriverName() === 'pgsql' ? '0' : 0);
        });

        $this->assertEquals($before, $this->currentTimeout());
    }

    private function slowQuery(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'SELECT pg_sleep(2)',
            // SLEEP() interrupted by MySQL's limit returns 1 rather than failing, so a query that
            // really works is needed
            default => 'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c',
        };
    }

    private function currentTimeout(): mixed
    {
        $row = (array) DB::selectOne(match (DB::connection()->getDriverName()) {
            'pgsql' => 'SHOW statement_timeout',
            'mariadb' => 'SELECT @@SESSION.max_statement_time AS value',
            default => 'SELECT @@SESSION.max_execution_time AS value',
        });

        return array_values($row)[0];
    }
}
