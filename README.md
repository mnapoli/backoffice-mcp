# Back-office MCP

Let an AI agent read your Laravel application's database through a strict, read-only whitelist of Eloquent models.

It is meant for internal tools: an admin server for you, a support server for your support team… Each server exposes three tools: `schema`, `query` and `count`. It is built on [`laravel/mcp`](https://github.com/laravel/mcp) and [`spatie/laravel-query-builder`](https://github.com/spatie/laravel-query-builder).

Anything absent from the whitelist is unreachable. A column that is not listed is never selected nor returned, and a filter, sort, relation or resource that is not listed is rejected rather than silently ignored.

## Installation

```sh
composer require mnapoli/backoffice-mcp
```

## Declaring a server

Create one class per audience, and declare what it can read:

```php
use BackofficeMcp\Filter;
use BackofficeMcp\McpServer;
use BackofficeMcp\Resource;

class AdminMcpServer extends McpServer
{
    protected string $name = 'Acme Admin';

    protected function resources(): array
    {
        return [
            Resource::for(Team::class)
                ->columns('id', 'name', 'plan', 'owner_id', 'created_at', 'deleted_at')
                ->filter('name', Filter::Partial)
                ->filter('plan', Filter::Exact)
                ->filter('created_at', Filter::Operator)
                ->sorts('id', 'created_at')
                ->include('owner', 'users')
                ->include('projects', 'projects'),

            Resource::for(User::class)
                ->columns('id', 'name', 'email', 'created_at')
                ->filter('email', Filter::Exact),

            Resource::for(Project::class)
                ->columns('id', 'team_id', 'name', 'created_at')
                ->filter('team_id', Filter::Exact),
        ];
    }
}
```

- `Resource::for()` takes the model, and optionally the name the agent will use for it (the table name by default).
- `columns()` lists what may be read. It is also the default `SELECT`.
- `filter()` makes a column filterable:
    - `Filter::Exact` matches the value,
    - `Filter::Partial` matches it anywhere in the column,
    - `Filter::Operator` accepts a leading `>`, `<`, `>=`, `<=` or `<>`, for example to filter on date ranges.
- `sorts()` lists the columns that may be sorted on.
- `include()` allows a relation to be loaded or counted. Related rows are read through the whitelist of the resource named as the second argument, never through the model directly.

Soft-deleted rows are excluded unless the agent asks for them with `trashed`.

The model's own `$hidden`, `$visible` and `$appends` are ignored: the whitelist alone decides what a row contains.

Describe your domain to the agent by overriding `$instructions`, for example to point at where the models and migrations live.

## Exposing it

Publish the configuration file:

```sh
php artisan vendor:publish --tag=backoffice-mcp-config
```

Then declare your servers in `config/backoffice-mcp.php`:

```php
return [
    'servers' => [
        'admin' => [
            'server' => App\Mcp\AdminMcpServer::class,
            'path' => 'mcp/admin',
            'auth' => [
                'token' => env('ADMIN_MCP_TOKEN'),
            ],
        ],
        'support' => [
            'server' => App\Mcp\SupportMcpServer::class,
            'path' => 'mcp/support',
            'auth' => [
                'token' => env('SUPPORT_MCP_TOKEN'),
            ],
        ],
    ],
];
```

Each server has its own bearer token. **When the token is empty, the route is not registered at all**: the URL answers 404, so a server stays off in every environment until you set its token there.

Generate a token with `openssl rand -hex 32`, then connect a client:

```sh
claude mcp add acme-admin --transport http https://example.com/mcp/admin \
    --header "Authorization: Bearer $TOKEN"
```

Requests are throttled to 60 per minute per client (`requests_per_minute` to change it). Every tool call is logged with `Log::info()`.

## Limits

Each server can override its limits:

```php
class SupportMcpServer extends McpServer
{
    public int $maxLimit = 50;
    public int $queryTimeout = 3;
    // ...
}
```

| Property | Default | |
|-|-|-|
| `defaultLimit` | 25 | Rows returned when the agent does not say. |
| `maxLimit` | 200 | Rows returned at most. Pagination is offset-based, and every sort ends on the primary key so that pages are stable. |
| `maxIncludedRows` | 25 | Related rows loaded per row for an included relation (the most recent ones). Use `counts` to count them all. |
| `maxTotalIncludedRows` | 10 000 | Related rows one request may load in total (rows × relations × related rows). A request over it is refused, saying which limit to lower. |
| `maxResponseBytes` | 100 000 | JSON size of one page of rows. A page over it is trimmed to what fits and comes back with `next_offset` and a `suggested_limit`. The default keeps a response to what an agent can afford to read. |
| `queryTimeout` | 8 | Seconds all the queries of one request may run, divided between them. `0` turns it off. MySQL, MariaDB and PostgreSQL only. |

On AWS Lambda behind API Gateway, keep `maxResponseBytes` under about 1 MB: the response is encoded several times on its way out (structured content, text content, then the Lambda payload), and API Gateway caps it at 6 MB.

## What is deliberately missing

There is no `GROUP BY` and no raw SQL. The agent aggregates with the `count` tool, or with the `counts` argument of `query` for a per-row relation count.

Writes are not supported.

## Testing your whitelist

Tools can be called in tests on your own server:

```php
use BackofficeMcp\Tools\QueryTool;

AdminMcpServer::tool(QueryTool::class, ['resource' => 'teams', 'filter' => ['plan' => 'pro']])
    ->assertOk()
    ->assertStructuredContent(fn ($json) => $json->where('returned', 1)->etc());
```

`AdminMcpServer::tool(SchemaTool::class)` returns the whole whitelist, with the database type of each column. Use it to check that every whitelisted column exists in your schema.

## Contributing

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests run on SQLite. To run them against a real server, which is the only way to test the query timeout, set `DB_CONNECTION` (`mysql`, `mariadb` or `pgsql`) and `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`.
