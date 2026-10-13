<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use Illuminate\Database\QueryException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use BackofficeMcp\QueryTimeout;
use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tools\BackofficeTool;
use PDOException;

class QueryTimeoutTest extends TestCase
{
    public function test_it_recognises_a_cancelled_query_on_every_supported_database(): void
    {
        $this->assertTrue(QueryTimeout::cancelled($this->queryException('HY000', 3024))); // MySQL
        $this->assertTrue(QueryTimeout::cancelled($this->queryException('70100', 1969))); // MariaDB
        $this->assertTrue(QueryTimeout::cancelled($this->queryException('57014', 7))); // PostgreSQL

        $this->assertFalse(QueryTimeout::cancelled($this->queryException('42S02', 1146)));
    }

    public function test_a_query_cancelled_by_the_timeout_is_answered_rather_than_reported(): void
    {
        // A cancelled query would otherwise escape into the generic "something went wrong", which
        // reads to an agent as worth retrying
        $response = $this->toolThrowing($this->queryException('HY000', 3024))->handle(new Request);

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('took too long', (string) $response->content());
    }

    public function test_any_other_database_error_still_escapes_to_be_reported(): void
    {
        $this->expectException(QueryException::class);

        $this->toolThrowing($this->queryException('42S02', 1146))->handle(new Request);
    }

    public function test_the_time_budget_is_shared_between_the_statements_a_request_takes(): void
    {
        $server = $this->app->make(AdminServer::class, ['transport' => new FakeTransporter]);
        $server->queryTimeout = 5;
        $tool = $this->toolThrowing(null, $server);

        // A budget applied per statement is not a budget: seven of them at five seconds each is
        // thirty-five
        $this->assertSame(5000, $tool->perStatement(1));
        $this->assertSame(714, $tool->perStatement(7));
        // Never rounded down to zero, which the database reads as no limit at all
        $this->assertSame(1, $tool->perStatement(100_000));

        // ...but a configured zero is that same "no limit", on purpose
        $server->queryTimeout = 0;
        $this->assertSame(0, $tool->perStatement(7));
    }

    private function queryException(string $sqlState, int $code): QueryException
    {
        $previous = new PDOException('Query failed');
        $previous->errorInfo = [$sqlState, $code, 'Query failed'];

        return new QueryException('testing', 'select 1', [], $previous);
    }

    private function toolThrowing(?QueryException $exception, ?AdminServer $server = null): BackofficeTool
    {
        $server ??= $this->app->make(AdminServer::class, ['transport' => new FakeTransporter]);

        return new class($server, $exception) extends BackofficeTool
        {
            public function __construct(AdminServer $server, private readonly ?QueryException $exception)
            {
                parent::__construct($server);
            }

            protected function run(Request $request): Response|ResponseFactory
            {
                throw $this->exception ?? new \LogicException('Not expected to run');
            }

            public function perStatement(int $statements): int
            {
                return $this->millisecondsPerStatement($statements);
            }
        };
    }
}
