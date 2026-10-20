<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tests\Fixtures\Member;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Passport\Token;

class OAuthTest extends TestCase
{
    private const string REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    protected function getPackageProviders($app): array
    {
        return [PassportServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', Member::class);
        $app['config']->set('passport.private_key', self::keys()['private']);
        $app['config']->set('passport.public_key', self::keys()['public']);
        $app['config']->set('backoffice-mcp.servers', [
            'admin' => ['server' => AdminServer::class, 'path' => 'mcp/admin', 'auth' => ['oauth' => true, 'gate' => 'use-admin-mcp']],
            'throttled' => ['server' => AdminServer::class, 'path' => 'mcp/throttled', 'auth' => ['oauth' => true, 'gate' => 'use-admin-mcp'], 'requests_per_minute' => 2],
            'off' => ['server' => AdminServer::class, 'path' => 'mcp/off', 'auth' => ['oauth' => false, 'gate' => 'use-admin-mcp']],
        ]);

        // The consent screen is the application's: this one hands the test what the form posts
        Passport::authorizationView(fn(array $parameters) => response()->json(['auth_token' => $parameters['authToken']]));
        Gate::define('use-admin-mcp', fn(Member $member) => $member->email === 'admin@example.com');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/passport/database/migrations');
    }

    public function test_a_user_who_passes_the_gate_connects_through_the_whole_oauth_flow(): void
    {
        $token = $this->connect($this->member('admin@example.com'));

        $this->postJson('/mcp/admin', $this->ping(), $this->bearer($token))->assertOk();
    }

    public function test_a_user_who_does_not_pass_the_gate_is_refused(): void
    {
        $token = $this->connect($this->member('customer@example.com'));

        $this->postJson('/mcp/admin', $this->ping(), $this->bearer($token))->assertForbidden();
    }

    public function test_a_request_without_a_valid_token_is_pointed_at_the_oauth_discovery(): void
    {
        $unauthenticated = [
            $this->postJson('/mcp/admin', $this->ping()),
            $this->postJson('/mcp/admin', $this->ping(), $this->bearer('not-a-token')),
            // Mcp::web registers a GET and a DELETE alongside the POST and hands back only the POST
            $this->get('/mcp/admin'),
            $this->delete('/mcp/admin'),
        ];
        foreach ($unauthenticated as $response) {
            $response->assertUnauthorized();
            $this->assertSame(
                'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/admin"',
                $response->headers->get('WWW-Authenticate'),
            );
        }

        $this->getJson('/.well-known/oauth-protected-resource/mcp/admin')
            ->assertOk()
            ->assertJson(['resource' => 'http://localhost/mcp/admin', 'authorization_servers' => ['http://localhost']]);
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJson([
                'authorization_endpoint' => 'http://localhost/oauth/authorize',
                'token_endpoint' => 'http://localhost/oauth/token',
                'registration_endpoint' => 'http://localhost/oauth/register',
            ]);
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $token = $this->connect($this->member('admin@example.com'));
        Token::query()->update(['revoked' => true]);

        $this->postJson('/mcp/admin', $this->ping(), $this->bearer($token))->assertUnauthorized();
    }

    public function test_a_revoked_client_is_refused(): void
    {
        $token = $this->connect($this->member('admin@example.com'));
        Client::query()->update(['revoked' => true]);
        // Passport memoizes the client for the rest of the request, which a test process is
        Once::flush();
        $this->postJson('/mcp/admin', $this->ping(), $this->bearer($token))->assertUnauthorized();
    }

    public function test_every_tool_call_is_logged_with_who_made_it(): void
    {
        $admin = $this->member('admin@example.com');
        $token = $this->connect($admin);
        Log::spy();

        $this->postJson('/mcp/admin', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'schema', 'arguments' => []],
        ], $this->bearer($token))->assertOk();

        Log::shouldHaveReceived('info')->with('MCP call: Admin schema', ['user_id' => $admin->id]);
    }

    public function test_each_server_throttles_on_its_own(): void
    {
        $token = $this->connect($this->member('admin@example.com'));

        $this->postJson('/mcp/throttled', $this->ping(), $this->bearer($token))->assertOk();
        $this->postJson('/mcp/throttled', $this->ping(), $this->bearer($token))->assertOk();
        $this->postJson('/mcp/throttled', $this->ping(), $this->bearer($token))->assertTooManyRequests();
        // A counter per server, not shared with the other one
        $this->postJson('/mcp/admin', $this->ping(), $this->bearer($token))->assertOk();
    }

    public function test_a_server_with_oauth_turned_off_has_no_route(): void
    {
        $token = $this->connect($this->member('admin@example.com'));

        $this->postJson('/mcp/off', $this->ping(), $this->bearer($token))->assertNotFound();
    }

    /**
     * What an MCP client like Claude does: registers itself, sends the user to the consent screen
     * with a PKCE challenge, then exchanges the code it gets back for an access token.
     */
    private function connect(Member $member): string
    {
        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT_URI]])
            ->assertCreated()
            ->json('client_id');
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $authToken = $this->actingAs($member)->get('/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk()->json('auth_token');
        $location = $this->post('/oauth/authorize', ['state' => 'state', 'client_id' => $clientId, 'auth_token' => $authToken])
            ->assertRedirect()
            ->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
            'code' => $query['code'] ?? null,
        ])->assertOk()->json('access_token');

        // From here on, the MCP client has the token and no session
        $this->app['auth']->forgetGuards();
        $this->assertIsString($token);

        return $token;
    }

    private function member(string $email): Member
    {
        return Member::query()->create(['name' => 'Member', 'email' => $email]);
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

    /**
     * @return array{private: string, public: string}
     */
    private static function keys(): array
    {
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            assert($key !== false);
            openssl_pkey_export($key, $private);
            $details = openssl_pkey_get_details($key);
            assert($details !== false);
            self::$keys = ['private' => $private, 'public' => $details['key']];
        }

        return self::$keys;
    }
}
