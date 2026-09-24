<?php

declare(strict_types=1);

// Include-only helper: never reaches the browser as an entry script. When this
// file IS the request's entry script the request is direct HTTP access, so it
// is refused with 403. Legitimate includes (endpoints, console, tests) always
// have a different SCRIPT_FILENAME.
if (
    (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__)
    || (isset($_SERVER['PHP_SELF']) && realpath((string) $_SERVER['PHP_SELF']) === __FILE__)
) {
    http_response_code(403);
    exit;
}

/**
 * Request-scheme detection, for cookie flags.
 *
 * WHY THIS EXISTS
 * ---------------
 * The API endpoints hand-roll their session cookie parameters
 * (session_set_cookie_params / setcookie) rather than letting Laravel's session
 * middleware own them. That meant SESSION_SECURE_COOKIE in .env was ignored on
 * every API route: a cookie was flagged Secure only when $_SERVER['HTTPS'] was
 * set. On a platform that terminates TLS at a load balancer and forwards plain
 * HTTP to the container, PHP never sees HTTPS — so BOTH the PHP session cookie
 * and the staff bearer-token cookie went out without Secure, and were replayable
 * by anyone able to get the browser to issue one plain-HTTP request. The routed
 * (Inertia) pages were unaffected because Laravel's own middleware reads
 * config('session.secure').
 *
 * Laravel's TrustProxies middleware cannot fix this: these endpoints bootstrap
 * the framework and never dispatch a request through the HTTP kernel, so no
 * middleware ever runs. The scheme has to be resolved here instead.
 */

/**
 * Addresses whose X-Forwarded-* headers may be believed.
 *
 * Defaults to the private, loopback and link-local ranges — where a same-host,
 * same-VPC or same-cluster load balancer always sits. A proxy in a PUBLIC range
 * (some hosted platforms expose one) must be listed explicitly with
 * TRUSTED_PROXY_IPS; until it is, its headers are correctly ignored.
 *
 * SECURITY NOTE: these ranges describe "the peer could be our proxy", which is
 * all that is needed to decide a cookie flag. Do NOT reuse this list to trust
 * X-Forwarded-For for rate limiting or auditing without re-checking the threat
 * model: a spoofed X-Forwarded-Proto can only ever make a cookie stricter, but a
 * spoofed X-Forwarded-For would let a caller rotate its apparent address.
 */
const TRUSTED_PROXY_DEFAULT_RANGES = [
    '127.0.0.0/8',
    '::1/128',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
    '169.254.0.0/16',
    'fc00::/7',
    'fe80::/10',
];

/**
 * Absolute paths are never accepted from a header, so the forwarded scheme only
 * has to be compared against this one token.
 */
const TRUSTED_HTTPS_SCHEME = 'https';

/**
 * True when this request should be treated as arriving over HTTPS.
 *
 * First match wins, and the order is deliberate:
 *
 *   1. SESSION_SECURE_COOKIE, when the operator set it explicitly. An explicit
 *      choice is honoured in BOTH directions — a deployment that declares
 *      itself HTTP-only is not silently overridden into Secure, and a
 *      production deployment gets Secure even when its proxy is unrecognised.
 *   2. Production (APP_ENV=production): TLS is mandatory there. This mirrors
 *      Laravel's own default for config/session.php, so routed and unrouted
 *      requests agree, and it is what actually closes the bug on a platform
 *      whose proxy address is not in the trusted list.
 *   3. $_SERVER['HTTPS'] — the web server terminated TLS itself.
 *   4. X-Forwarded-Proto, but ONLY from a trusted peer.
 *   5. SERVER_PORT 443.
 */
function requestIsSecure(): bool
{
    $declared = declaredSessionSecureCookie();
    if ($declared !== null) {
        return $declared;
    }

    if (appIsProduction()) {
        return true;
    }

    if (directHttpsDetected()) {
        return true;
    }

    if (peerIsTrustedProxy() && forwardedProtoIsHttps()) {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * The operator's explicit SESSION_SECURE_COOKIE choice, or null when they did
 * not make one.
 *
 * The env var is read directly rather than going through config() so that
 * "unset" stays distinguishable from "explicitly false". config('session.secure')
 * substitutes a default (true in production), and an explicit false must not be
 * overridden by the request-scheme fallbacks below.
 */
function declaredSessionSecureCookie(): ?bool
{
    $raw = null;

    if (function_exists('env')) {
        try {
            $raw = env('SESSION_SECURE_COOKIE');
        } catch (Throwable $error) {
            $raw = null;
        }
    }

    if ($raw === null) {
        $fromProcess = getenv('SESSION_SECURE_COOKIE');
        $raw = $fromProcess === false ? null : $fromProcess;
    }

    return normalizeBooleanSetting($raw);
}

/**
 * Coerce an env value into a bool, or null when it is absent/unrecognisable.
 * Laravel's env() already turns true/false/(true)/on into booleans; the string
 * forms are handled here so the getenv() fallback behaves identically.
 */
function normalizeBooleanSetting($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null) {
        return null;
    }

    $text = strtolower(trim((string) $value));

    if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    // An empty string is how a blanked .env entry arrives; treat it as unset
    // rather than as a deliberate "off".
    return null;
}

/**
 * True when the application declares itself a production environment.
 */
function appIsProduction(): bool
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

    return strtolower(trim($environment)) === 'production';
}

/**
 * True when the web server itself reports TLS (Apache/nginx + FastCGI set
 * HTTPS=on). Some SAPIs set it to 'off' on plain HTTP, hence the comparison.
 */
function directHttpsDetected(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';

    if (is_bool($https)) {
        return $https;
    }

    $text = strtolower(trim((string) $https));

    return $text !== '' && $text !== 'off' && $text !== '0';
}

/**
 * The IP the request was actually received from.
 *
 * Deliberately NOT resolveClientIpAddress(): that helper prefers REMOTE_ADDR
 * for rate limiting and only falls back to X-Forwarded-For. Trusting a proxy
 * needs the opposite starting point — the immediate peer — so the two questions
 * are kept separate.
 */
function proxyPeerIpAddress(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/**
 * True when the immediate peer is allowed to set forwarding headers.
 */
function peerIsTrustedProxy(): bool
{
    $peer = proxyPeerIpAddress();
    if ($peer === '') {
        return false;
    }

    foreach (trustedProxyRanges() as $range) {
        if ($range === '*') {
            return true;
        }
        if (ipMatchesCidrRange($peer, $range)) {
            return true;
        }
    }

    return false;
}

/**
 * The configured trusted-proxy ranges, or the private/loopback defaults.
 *
 * TRUSTED_PROXY_IPS is a comma-separated list of IPs and CIDR ranges, and an
 * explicit list REPLACES the defaults so an operator can narrow trust as well
 * as widen it. The single value '*' trusts any peer, which is only appropriate
 * when the platform's proxy address genuinely cannot be known — and even then
 * requirement 2 of requestIsSecure() already covers production.
 */
function trustedProxyRanges(): array
{
    $configured = '';

    if (function_exists('env')) {
        try {
            $configured = (string) env('TRUSTED_PROXY_IPS', '');
        } catch (Throwable $error) {
            $configured = '';
        }
    }

    if (trim($configured) === '') {
        $fromProcess = getenv('TRUSTED_PROXY_IPS');
        $configured = is_string($fromProcess) ? $fromProcess : '';
    }

    $ranges = [];
    foreach (explode(',', $configured) as $entry) {
        $entry = trim($entry);
        if ($entry !== '') {
            $ranges[] = $entry;
        }
    }

    return $ranges === [] ? TRUSTED_PROXY_DEFAULT_RANGES : $ranges;
}

/**
 * True when the left-most X-Forwarded-Proto value is https.
 *
 * The left-most value is the scheme the original client used (each proxy
 * appends the scheme it observed), which is the same convention Symfony's
 * Request::getScheme() follows once the proxy is trusted. Only the single
 * 'https' token can ever be a match, so a header carrying a path or an
 * arbitrary string cannot influence the result.
 */
function forwardedProtoIsHttps(): bool
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded === '') {
        return false;
    }

    $parts = explode(',', $forwarded);
    $first = strtolower(trim((string) $parts[0]));

    return $first === TRUSTED_HTTPS_SCHEME;
}

/**
 * Normalize an address for range comparison: an IPv4-mapped IPv6 address
 * (::ffff:192.168.1.5) is reduced to its IPv4 form so it can match an IPv4
 * range, which is how a dual-stack proxy usually presents a v4 peer.
 */
function normalizeIpForRangeMatching(string $ip): string
{
    $ip = trim($ip);

    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return substr($ip, 7);
    }

    return $ip;
}

/**
 * True when $ip falls inside $range. $range is either a bare IP or CIDR
 * notation, and a family mismatch (v4 address against a v6 range) is never a
 * match rather than an error.
 */
function ipMatchesCidrRange(string $ip, string $range): bool
{
    $ip = normalizeIpForRangeMatching($ip);
    $range = trim($range);

    if ($ip === '' || $range === '') {
        return false;
    }

    if (strpos($range, '/') === false) {
        return $ip === normalizeIpForRangeMatching($range);
    }

    [$subnet, $bits] = explode('/', $range, 2);
    if (!ctype_digit(trim($bits))) {
        return false;
    }

    $ipBinary = @inet_pton($ip);
    $subnetBinary = @inet_pton(trim($subnet));
    if ($ipBinary === false || $subnetBinary === false) {
        return false;
    }
    if (strlen($ipBinary) !== strlen($subnetBinary)) {
        return false; // v4 address against a v6 range, or vice versa.
    }

    $prefixLength = (int) trim($bits);
    $maxBits = strlen($ipBinary) * 8;
    if ($prefixLength < 0 || $prefixLength > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefixLength, 8);
    if ($wholeBytes > 0 && substr($ipBinary, 0, $wholeBytes) !== substr($subnetBinary, 0, $wholeBytes)) {
        return false;
    }

    $remainingBits = $prefixLength % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
}
