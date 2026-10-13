<?php declare(strict_types=1);

namespace BackofficeMcp;

use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use BackofficeMcp\Tools\CountTool;
use BackofficeMcp\Tools\BackofficeTool;
use BackofficeMcp\Tools\QueryTool;
use BackofficeMcp\Tools\SchemaTool;

/**
 * A read-only MCP server over a whitelist of Eloquent models.
 *
 * Extend it once per audience (an admin server, a support server…), each with its own whitelist
 * and limits, and expose each one in `config/backoffice-mcp.php`.
 */
abstract class McpServer extends Server
{
    protected string $instructions = <<<'MARKDOWN'
        Read-only access to the application's database, through a whitelist of resources.

        Start with the `schema` tool. It lists the resources that exist and, for each, the columns
        that can be read, filtered and sorted on, and the relations that can be included. Only what
        it lists is accepted: everything else is rejected rather than silently dropped. Some tables
        and columns are excluded on purpose, so their absence is not a bug.

        What the resources, columns and statuses mean is not described here. If you have access to
        the application's source code, explore it to find out, starting in `app/`.

        There is no `GROUP BY`. Aggregate with the `count` tool, or with the `counts` argument of
        `query` for a per-row relation count.
        MARKDOWN;

    /**
     * What this server is for and what its data is about, given to the agent before the generic
     * `$instructions` on how to use the tools.
     */
    protected string $description = '';

    /** Rows a query returns when the caller does not say. */
    public int $defaultLimit = 25;

    /** Rows a query may return at most, whatever the caller asks for. */
    public int $maxLimit = 200;

    /**
     * Rows of an included relation loaded per parent row. `$maxLimit` caps the parent rows only,
     * and without this cap a single include would load every related row of each of them.
     */
    public int $maxIncludedRows = 25;

    /**
     * Related rows one request may fetch in total. Each cap above bounds one dimension, but what
     * gets loaded is their product: rows, times relations, times related rows each. A request
     * over it is refused before anything runs, saying which limit to lower.
     */
    public int $maxTotalIncludedRows = 10_000;

    /**
     * Bytes of JSON one page of rows may weigh. A page over it is trimmed to what fits, and says
     * so, rather than refused. The default keeps one response to a few tens of thousands of
     * tokens, which is what the agent reading it can afford.
     */
    public int $maxResponseBytes = 100_000;

    /**
     * Seconds all the queries of one request may run for before the database cancels them, so
     * that a badly filtered query cannot hold a connection open. Zero turns the limit off.
     * MySQL, MariaDB and PostgreSQL only.
     */
    public int $queryTimeout = 8;

    private ?Catalog $catalog = null;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        if (trim($this->description) !== '') {
            $this->instructions = trim($this->description) . "\n\n" . trim($this->instructions);
        }

        $this->tools = [
            ...$this->tools,
            new SchemaTool($this),
            new QueryTool($this),
            new CountTool($this),
        ];
    }

    /**
     * The whitelist: what this server can read.
     *
     * @return list<Resource>
     */
    abstract protected function resources(): array;

    public function catalog(): Catalog
    {
        return $this->catalog ??= new Catalog($this->resources(), $this->maxIncludedRows);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Call a tool in a test: `AdminMcpServer::tool(QueryTool::class, ['resource' => 'users'])`.
     *
     * The tools of this package belong to a server, so they cannot be built from their class name
     * alone the way `Server::tool()` builds them.
     *
     * @param  Tool|class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     */
    public static function tool(Tool|string $tool, array $arguments = []): TestResponse
    {
        $container = Container::getInstance();

        if (is_string($tool) && is_a($tool, BackofficeTool::class, true)) {
            $tool = new $tool($container->make(static::class, ['transport' => new FakeTransporter]));
        }

        return (new PendingTestResponse($container, static::class))->tool($tool, $arguments);
    }
}
