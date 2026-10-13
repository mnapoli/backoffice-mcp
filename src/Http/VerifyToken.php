<?php declare(strict_types=1);

namespace BackofficeMcp\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the bearer token of a request against the one its server was registered with.
 *
 * The route carries a SHA-256 hash of the token as the middleware parameter rather than the token
 * itself, so that `route:list` and the route cache never hold the secret, and so that the check
 * still works when routes are cached and the configuration is not read at all.
 */
final class VerifyToken
{
    public function handle(Request $request, Closure $next, string $tokenHash): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '' || ! hash_equals($tokenHash, hash('sha256', $token))) {
            abort(401, 'Invalid token.');
        }

        return $next($request);
    }
}
