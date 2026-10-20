<?php declare(strict_types=1);

namespace BackofficeMcp\Tools;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use BackofficeMcp\McpServer;
use BackofficeMcp\QueryTimeout;
use BackofficeMcp\Resource;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;

/**
 * Shared behaviour of the tools: every call is logged, and the whitelist rejecting an argument is
 * reported back to the client rather than raised as a 500.
 */
abstract class BackofficeTool extends Tool
{
    public function __construct(protected readonly McpServer $server) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        // Behind OAuth, who asked: the token alone names nobody. It comes first so that an argument
        // named `user_id` cannot pass for it.
        $user = Auth::id();
        Log::info(sprintf('MCP call: %s %s', $this->server->name(), $this->name()), ($user !== null ? ['user_id' => $user] : []) + $request->all());

        try {
            return $this->run($request);
        } catch (InvalidArgumentException|InvalidQuery $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            // Answered here so the caller gets something it can act on, rather than the generic
            // error that reads as worth retrying -- which is a second slow query. Any other query
            // error is a bug the caller cannot do anything about, and is still reported.
            if (! QueryTimeout::cancelled($e)) {
                throw $e;
            }

            return Response::error('The query took too long and was cancelled. ' . $this->narrowingHint());
        }
    }

    abstract protected function run(Request $request): Response|ResponseFactory;

    /**
     * What the caller can change to make the query cheaper, which differs per tool: telling
     * someone to ask for fewer relations is useless on a tool that takes none.
     */
    protected function narrowingHint(): string
    {
        return 'Narrow it with a filter.';
    }

    /**
     * Run the queries under the server's time budget.
     *
     * The database limits each statement, and an eager load is a statement of its own, so the
     * budget is divided across the statements the request will run: applied to each of them
     * instead, seven statements at five seconds each is thirty-five, which is past the time limit
     * of most HTTP requests -- and a request that times out takes the error handling above with it.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withQueryTimeout(Resource $resource, Closure $callback, int $statements = 1): mixed
    {
        return QueryTimeout::run(
            $resource->newModel()->getConnection(),
            $this->millisecondsPerStatement($statements),
            $callback,
        );
    }

    /**
     * The share of the request's time budget one statement gets. A configured zero means "no
     * limit" to the database, which is what it is there for, so it is passed through. A share that
     * rounds down to zero is not, since that would mean the same.
     */
    protected function millisecondsPerStatement(int $statements): int
    {
        $budget = $this->server->queryTimeout * 1000;

        return $budget === 0 ? 0 : max(1, intdiv($budget, max(1, $statements)));
    }
}
