<?php declare(strict_types=1);

namespace BackofficeMcp\Http;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request with a Passport access token, then lets it through only if the user
 * passes the server's gate.
 *
 * Passport's own `auth:api` guard would need Passport's `HasApiTokens` trait on the user model,
 * which collides with Sanctum's in an application that already uses Sanctum. The token is checked
 * the way that guard checks it, and the user is retrieved with the same user provider as the one
 * who approved the client on Passport's consent screen, with no requirement on the model.
 */
final class VerifyOAuthToken
{
    public function __construct(
        private readonly ResourceServer $server,
        private readonly ClientRepository $clients,
    ) {}

    public function handle(Request $request, Closure $next, string $gate): Response
    {
        $user = $this->user($request);

        if ($user === null) {
            // Points the MCP client at the discovery that starts the OAuth flow
            $metadata = route('mcp.oauth.protected-resource.nested', ['path' => $request->path()]);

            return response()->json(['error' => 'invalid_token'], 401, [
                'WWW-Authenticate' => sprintf('Bearer realm="mcp", resource_metadata="%s"', $metadata),
            ]);
        }

        if (Gate::forUser($user)->denies($gate)) {
            abort(403, 'This user is not allowed to use this MCP server.');
        }

        // For the throttle, and for tools that want to know who asks
        Auth::setUser($user);

        return $next($request);
    }

    private function user(Request $request): ?Authenticatable
    {
        if (($request->bearerToken() ?? '') === '') {
            return null;
        }

        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $clientId = $psr->getAttribute('oauth_client_id');
        $client = is_string($clientId) ? $this->clients->findActive($clientId) : null;
        $providerName = $this->providerName();
        // A client registered for another kind of user, the way Passport's guard sorts them out
        $clientProvider = $client?->getAttribute('provider');
        if ($client === null || ($clientProvider !== null && $clientProvider !== $providerName)) {
            return null;
        }

        // A client credentials token is issued to the client itself, with no user behind it
        $userId = $psr->getAttribute('oauth_user_id');
        if (! is_string($userId) || $userId === '' || ($userId === $clientId && $client->hasGrantType('client_credentials'))) {
            return null;
        }

        return Auth::createUserProvider($providerName)?->retrieveById($userId);
    }

    /**
     * The user provider of the guard that Passport's consent screen logs users in with.
     */
    private function providerName(): ?string
    {
        $guard = config('passport.guard') ?? config('auth.defaults.guard');
        $provider = is_string($guard) ? config("auth.guards.$guard.provider") : null;

        return is_string($provider) ? $provider : null;
    }
}
