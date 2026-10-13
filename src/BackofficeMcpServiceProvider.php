<?php declare(strict_types=1);

namespace BackofficeMcp;

use BackofficeMcp\Http\VerifyToken;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use LogicException;

final class BackofficeMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/backoffice-mcp.php', 'backoffice-mcp');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/backoffice-mcp.php' => $this->app->configPath('backoffice-mcp.php'),
        ], 'backoffice-mcp-config');

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $servers = config('backoffice-mcp.servers');
        foreach (is_array($servers) ? $servers : [] as $name => $server) {
            $this->expose((string) $name, $server);
        }
    }

    /**
     * Expose a server over HTTP, behind its authentication. Without any, nothing is registered at
     * all, so the path answers 404 rather than 401.
     */
    private function expose(string $name, mixed $config): void
    {
        // A server that is wrong is reported whether or not it is turned on, so that the mistake
        // shows up in the environments where it is off, before it reaches the one where it is on.
        $server = is_array($config) ? $config['server'] ?? null : null;
        if (! is_string($server) || ! is_a($server, McpServer::class, true)) {
            throw $this->invalid($name, sprintf('needs a "server" key naming a class that extends %s', McpServer::class));
        }
        $path = $config['path'] ?? null;
        if (! is_string($path) || $path === '') {
            throw $this->invalid($name, 'needs a "path"');
        }
        $requestsPerMinute = $config['requests_per_minute'] ?? 60;
        if (! is_int($requestsPerMinute) || $requestsPerMinute < 1) {
            throw $this->invalid($name, 'needs a "requests_per_minute" that is a positive integer');
        }
        $auth = $config['auth'] ?? [];
        if (! is_array($auth)) {
            throw $this->invalid($name, 'needs an "auth" array');
        }
        // A misspelled method would otherwise leave the server off without a word
        $unknown = array_diff(array_keys($auth), ['token']);
        if ($unknown !== []) {
            throw $this->invalid($name, sprintf('has an unknown "auth" method "%s", the only one is "token"', implode('", "', $unknown)));
        }

        $token = $auth['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return;
        }

        // The middleware goes on a group rather than on the route `Mcp::web()` returns: it also
        // registers a GET and a DELETE on the same path, and returns only the POST, so the other
        // two would answer an unauthenticated 405 and say that the server is turned on here.
        //
        // The token check goes first because it is the cheaper refusal: a hash comparison,
        // against a throttle that goes to the cache store. The throttle is there for a runaway
        // agent rather than for an attacker: the token keeps those out.
        Route::middleware([
            VerifyToken::class . ':' . hash('sha256', $token),
            "throttle:$requestsPerMinute,1",
        ])->group(function () use ($path, $server) {
            Mcp::web($path, $server);
        });
    }

    private function invalid(string $name, string $problem): LogicException
    {
        return new LogicException(sprintf('The MCP server "%s" in config/backoffice-mcp.php %s.', $name, $problem));
    }
}
