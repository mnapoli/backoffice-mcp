<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use BackofficeMcp\BackofficeMcpServiceProvider;
use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tests\Fixtures\SupportServer;
use BackofficeMcp\Tests\Fixtures\Team;
use Illuminate\Support\Facades\Route;
use LogicException;

class RouteTest extends TestCase
{
    private const string ADMIN_TOKEN = 'admin-token';
    private const string SUPPORT_TOKEN = 'support-token';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('backoffice-mcp.servers', [
            'admin' => ['server' => AdminServer::class, 'path' => 'mcp/admin', 'auth' => ['token' => self::ADMIN_TOKEN]],
            'support' => ['server' => SupportServer::class, 'path' => 'mcp/support', 'auth' => ['token' => self::SUPPORT_TOKEN], 'requests_per_minute' => 5],
            'no-auth' => ['server' => AdminServer::class, 'path' => 'mcp/no-auth'],
            'off' => ['server' => AdminServer::class, 'path' => 'mcp/off', 'auth' => ['token' => null]],
            'empty' => ['server' => AdminServer::class, 'path' => 'mcp/empty', 'auth' => ['token' => '']],
        ]);
    }

    public function test_the_endpoint_rejects_a_request_without_a_token_whatever_the_method(): void
    {
        $this->postJson('/mcp/admin', $this->ping())->assertUnauthorized();
        // Mcp::web registers a GET and a DELETE alongside the POST and hands back only the POST
        $this->get('/mcp/admin')->assertUnauthorized();
        $this->delete('/mcp/admin')->assertUnauthorized();
    }

    public function test_the_endpoint_rejects_a_wrong_token(): void
    {
        $this->postJson('/mcp/admin', $this->ping(), $this->bearer('wrong'))->assertUnauthorized();
        $this->postJson('/mcp/admin', $this->ping(), ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_the_endpoint_accepts_its_token(): void
    {
        $this->postJson('/mcp/admin', $this->ping(), $this->bearer(self::ADMIN_TOKEN))->assertOk();
    }

    public function test_each_server_has_its_own_token(): void
    {
        $this->postJson('/mcp/support', $this->ping(), $this->bearer(self::ADMIN_TOKEN))->assertUnauthorized();
        $this->postJson('/mcp/admin', $this->ping(), $this->bearer(self::SUPPORT_TOKEN))->assertUnauthorized();
        $this->postJson('/mcp/support', $this->ping(), $this->bearer(self::SUPPORT_TOKEN))->assertOk();
    }

    public function test_no_route_exists_at_all_without_authentication(): void
    {
        $this->postJson('/mcp/no-auth', $this->ping(), $this->bearer(self::ADMIN_TOKEN))->assertNotFound();
        $this->postJson('/mcp/off', $this->ping(), $this->bearer(self::ADMIN_TOKEN))->assertNotFound();
        $this->postJson('/mcp/empty', $this->ping(), $this->bearer(''))->assertNotFound();
        $this->assertFalse(Route::getRoutes()->hasNamedRoute('mcp/off'));
    }

    public function test_the_token_never_appears_in_the_route_definition(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(self::ADMIN_TOKEN, implode(' ', $route->gatherMiddleware()));
        }
    }

    public function test_a_caller_holding_the_token_is_throttled(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/mcp/support', $this->ping(), $this->bearer(self::SUPPORT_TOKEN))->assertOk();
        }

        $this->postJson('/mcp/support', $this->ping(), $this->bearer(self::SUPPORT_TOKEN))->assertTooManyRequests();
    }

    public function test_a_caller_without_the_token_is_refused_before_the_throttle_runs(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->postJson('/mcp/support', $this->ping())->assertUnauthorized();
        }

        $this->postJson('/mcp/support', $this->ping(), $this->bearer(self::SUPPORT_TOKEN))->assertOk();
    }

    public function test_a_misconfigured_server_fails_even_without_a_token(): void
    {
        $misconfigurations = [
            'no server' => ['path' => 'mcp/x'],
            'not a server' => ['server' => Team::class, 'path' => 'mcp/x'],
            'no path' => ['server' => AdminServer::class],
            'bad throttle' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'requests_per_minute' => '60'],
            'auth not an array' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => 'secret'],
            'unknown auth method' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['tokne' => 'secret']],
            'oauth without a gate' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['oauth' => true]],
            'oauth turned off, without a gate' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['oauth' => false]],
            'oauth not a boolean' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['oauth' => 'yes', 'gate' => 'admin']],
            'a gate with a token' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['token' => 'secret', 'gate' => 'admin']],
            'both a token and oauth' => ['server' => AdminServer::class, 'path' => 'mcp/x', 'auth' => ['token' => 'secret', 'oauth' => true, 'gate' => 'admin']],
        ];

        foreach ($misconfigurations as $case => $server) {
            config()->set('backoffice-mcp.servers', ['broken' => $server]);

            try {
                (new BackofficeMcpServiceProvider($this->app))->boot();
                $this->fail("No exception for: $case");
            } catch (LogicException $e) {
                $this->assertStringContainsString('"broken"', $e->getMessage(), $case);
            }
        }
    }

    public function test_the_description_comes_before_the_generic_instructions(): void
    {
        $instructions = fn(string $path, string $token) => $this->postJson($path, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $this->bearer($token))->assertOk()->json('result.instructions');

        $support = $instructions('/mcp/support', self::SUPPORT_TOKEN);
        $this->assertStringStartsWith("The customers of Acme, for its support team.\n\nRead-only access", $support);
        $this->assertStringContainsString('Start with the `schema` tool', $support);

        // Without a description, the generic instructions alone
        $this->assertStringStartsWith('Read-only access', $instructions('/mcp/admin', self::ADMIN_TOKEN));
    }

    public function test_the_server_exposes_three_read_only_tools(): void
    {
        $response = $this->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->bearer(self::ADMIN_TOKEN))
            ->assertOk();

        $tools = collect($response->json('result.tools'));
        $this->assertSame(['schema', 'query', 'count'], $tools->pluck('name')->all());
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ping(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }
}
