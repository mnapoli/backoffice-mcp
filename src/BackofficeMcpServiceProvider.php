<?php declare(strict_types=1);

namespace BackofficeMcp;

use BackofficeMcp\Http\VerifyOAuthToken;
use BackofficeMcp\Http\VerifyToken;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;
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
        $oauth = false;
        foreach (is_array($servers) ? $servers : [] as $name => $server) {
            $oauth = $this->expose((string) $name, $server) === 'oauth' || $oauth;
        }

        // The discovery and client registration endpoints that MCP clients look for, shared by
        // every server behind OAuth. Registering a client needs no authentication and adds a row to
        // the database: the throttle keeps anyone from filling the table.
        if ($oauth) {
            Route::middleware('throttle:30,1,backoffice-mcp:oauth')->group(function () {
                Mcp::oauthRoutes();
            });
        }
    }

    /**
     * Expose a server over HTTP, behind its authentication. Without any, nothing is registered at
     * all, so the path answers 404 rather than 401.
     *
     * @return 'token'|'oauth'|null The authentication the server was exposed behind
     */
    private function expose(string $name, mixed $config): ?string
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
        $unknown = array_diff(array_keys($auth), ['token', 'oauth', 'gate']);
        if ($unknown !== []) {
            throw $this->invalid($name, sprintf('has an unknown "auth" key "%s", the only ones are "token", and "oauth" with its "gate"', implode('", "', $unknown)));
        }
        if (array_key_exists('token', $auth) && array_key_exists('oauth', $auth)) {
            throw $this->invalid($name, 'has both a "token" and "oauth" in "auth": a server is exposed behind one or the other, declare a second server on another path for the other');
        }
        $gate = $auth['gate'] ?? null;
        if (array_key_exists('oauth', $auth)) {
            $oauth = $auth['oauth'];
            if (! is_bool($oauth) && $oauth !== null) {
                throw $this->invalid($name, 'needs an "auth.oauth" that is true or false');
            }
            // Every user of the application can log in: without a gate, the customers of a SaaS
            // would read the admin server
            if (! is_string($gate) || $gate === '') {
                throw $this->invalid($name, 'needs an "auth.gate" naming the gate that decides which users can connect, any user of the application could otherwise');
            }
            if ($oauth === true && ! class_exists(Passport::class)) {
                throw $this->invalid($name, 'uses "auth.oauth", which needs Laravel Passport: composer require laravel/passport');
            }
            if ($oauth !== true) {
                return null;
            }

            // The user is known once the token is checked, so the throttle that follows keys on
            // the user rather than on the IP address
            $this->route($path, $server, [VerifyOAuthToken::class . ':' . $gate, $this->throttle($name, $requestsPerMinute)]);

            return 'oauth';
        }
        if ($gate !== null) {
            throw $this->invalid($name, 'has an "auth.gate" without "auth.oauth": a token is not tied to a user that a gate could check');
        }

        $token = $auth['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        // The token check goes first because it is the cheaper refusal: a hash comparison,
        // against a throttle that goes to the cache store. The throttle is there for a runaway
        // agent rather than for an attacker: the token keeps those out.
        $this->route($path, $server, [VerifyToken::class . ':' . hash('sha256', $token), $this->throttle($name, $requestsPerMinute)]);

        return 'token';
    }

    /**
     * The middleware goes on a group rather than on the route `Mcp::web()` returns: it also
     * registers a GET and a DELETE on the same path, and returns only the POST, so the other two
     * would answer an unauthenticated 405 and say that the server is turned on here.
     *
     * @param class-string<McpServer> $server
     * @param list<string> $middleware
     */
    private function route(string $path, string $server, array $middleware): void
    {
        Route::middleware($middleware)->group(function () use ($path, $server) {
            Mcp::web($path, $server);
        });
    }

    /**
     * A counter per server: without the prefix, a user's calls to one server would count against
     * another, and against every other unprefixed throttle of the application.
     */
    private function throttle(string $name, int $requestsPerMinute): string
    {
        return "throttle:$requestsPerMinute,1,backoffice-mcp:$name";
    }

    private function invalid(string $name, string $problem): LogicException
    {
        return new LogicException(sprintf('The MCP server "%s" in config/backoffice-mcp.php %s.', $name, $problem));
    }
}
