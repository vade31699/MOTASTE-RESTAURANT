<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Redirect plain-HTTP requests to the same URL over HTTPS (DPA 2.1: no
 * credentials or personal data over HTTP).
 *
 * The platform edge already terminates TLS and returns a 301 for HTTP (verified
 * live on the hosted site), so this never fires there. It exists for every
 * other way the app can be reached with the client's original scheme intact: a
 * bare server running the production code, a staging instance probed by raw IP,
 * or a proxy with the redirect turned off.
 *
 * The one thing it must never do is bounce a TLS-terminating proxy's HTTPS
 * request into a redirect loop, so it redirects only on POSITIVE evidence that
 * the client spoke plain HTTP. It reuses the API's trusted-proxy ranges and
 * CIDR matcher (public/api/_request_helpers.php) so the trust model has a
 * single definition.
 *
 * Scheme decision, first match wins:
 *   - HTTPS=on, or SERVER_PORT 443    -> direct TLS; never touch it.
 *   - trusted peer, X-Forwarded-Proto -> believe it (https keeps, http redirects).
 *   - trusted peer, no header         -> ambiguous; leave it alone (a proxy that
 *                                        does not forward the header must not
 *                                        be sent into a loop).
 *   - untrusted peer, any header      -> cannot be judged (a forged header must
 *                                        never force a redirect); assume TLS.
 *   - untrusted peer, no header       -> direct client connection with nothing
 *                                        forwarding TLS: provably plain HTTP.
 *
 * `local` and `testing` are exempt so `php artisan serve` (http://localhost)
 * and the test suite keep working.
 */
class ForceHttps
{
    /**
     * Permanent redirect that preserves the request method and body, so a form
     * POST carrying credentials is replayed over HTTPS rather than downgraded
     * to a GET (which a 301 would do) and dropped.
     */
    public const REDIRECT_STATUS = 308;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->exemptEnvironment()) {
            return $next($request);
        }

        if ($this->requestArrivedOverPlainHttp($request)) {
            return redirect()->to(
                'https://'.$request->getHost().$request->getRequestUri(),
                self::REDIRECT_STATUS,
            );
        }

        return $next($request);
    }

    /**
     * True only when the request is known to have arrived over plain HTTP.
     */
    private function requestArrivedOverPlainHttp(Request $request): bool
    {
        if ($request->isSecure() || (string) $request->getPort() === '443') {
            return false;
        }

        $this->loadTrustHelpers();

        $peer = trim((string) $request->server->get('REMOTE_ADDR', ''));
        $forwarded = trim((string) $request->headers->get('X-Forwarded-Proto', ''));

        if ($peer !== '' && $this->peerIsTrusted($peer)) {
            return $forwarded !== '' && $this->leftmostForwardedProto($forwarded) === 'http';
        }

        // An untrusted peer's forwarded header proves nothing about the real
        // scheme; a header at all means the request was relayed, so leave it.
        // Only a headerless direct connection is provably plain HTTP.
        return $forwarded === '';
    }

    /**
     * True when the immediate peer is allowed to set forwarding headers. Mirrors
     * peerIsTrustedProxy() in _request_helpers.php but reads the peer from the
     * request object (the canonical source inside the HTTP kernel) instead of
     * the $_SERVER superglobal.
     */
    private function peerIsTrusted(string $peer): bool
    {
        foreach (trustedProxyRanges() as $range) {
            if ($range === '*' || ipMatchesCidrRange($peer, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The left-most X-Forwarded-Proto value: the scheme the original client
     * used (each proxy appends what it observed), matching Symfony's convention.
     */
    private function leftmostForwardedProto(string $forwarded): string
    {
        $parts = explode(',', $forwarded);

        return strtolower(trim((string) ($parts[0] ?? '')));
    }

    private function loadTrustHelpers(): void
    {
        if (! function_exists('trustedProxyRanges')) {
            require_once __DIR__.'/../../../public/api/_request_helpers.php';
        }
    }

    /**
     * Resolve the environment from config, the way appIsProduction() does, so
     * the middleware and the API helpers agree and a test can flip it.
     */
    private function exemptEnvironment(): bool
    {
        $environment = '';

        if (function_exists('config')) {
            try {
                $environment = (string) config('app.env', '');
            } catch (Throwable $error) {
                $environment = '';
            }
        }

        if ($environment === '') {
            $environment = (string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        }

        return in_array(strtolower(trim($environment)), ['local', 'testing'], true);
    }
}
