<?php

use App\Models\User;

/**
 * Pull one directive (e.g. "script-src ...") out of a CSP header value so the
 * assertions below can talk about that directive only — 'unsafe-inline' is
 * still legitimate in style-src, so a whole-header check would be wrong.
 */
function cspDirectiveValue(string $policy, string $directive): string
{
    foreach (explode(';', $policy) as $part) {
        $part = trim($part);

        if (str_starts_with($part, $directive . ' ')) {
            return $part;
        }
    }

    return '';
}

/**
 * The inline <script> tags a strict script-src would actually block: no src
 * (so they execute inline), no nonce (so the header does not trust them), and
 * not a non-executable data block such as application/ld+json, which
 * script-src never applies to.
 *
 * @return list<string> The offending tags' attribute strings.
 */
function untrustedInlineScripts(string $html): array
{
    preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

    $untrusted = [];

    foreach ($matches[1] as $attributes) {
        if (preg_match('/\bsrc\s*=/i', $attributes)) {
            continue; // external script, allowlisted by host
        }

        if (preg_match('/\btype\s*=\s*["\']?application\/ld\+json/i', $attributes)) {
            continue; // JSON-LD data block, never executed
        }

        if (preg_match('/\bnonce\s*=/i', $attributes)) {
            continue; // trusted by the CSP header
        }

        $untrusted[] = trim($attributes);
    }

    return $untrusted;
}

it('blocks inline scripts while issuing a per-response nonce', function () {
    $response = $this->get('/');

    $response->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy');
    $scriptSrc = cspDirectiveValue($policy, 'script-src');

    expect($scriptSrc)->not->toBe('');
    expect($scriptSrc)->not->toContain("'unsafe-inline'");
    expect($scriptSrc)->toContain("'nonce-");

    // Styles legitimately keep 'unsafe-inline' — the pages set element.style.*
    // and carry style attributes.
    expect(cspDirectiveValue($policy, 'style-src'))->toContain("'unsafe-inline'");

    // The homepage carries no inline script except its ld+json data block, so
    // nothing there depends on the nonce.
    expect(untrustedInlineScripts((string) $response->getContent()))->toBe([]);
});

it('issues a fresh nonce for every response', function () {
    preg_match("/'nonce-([^']+)'/", (string) $this->get('/')->headers->get('Content-Security-Policy'), $first);
    preg_match("/'nonce-([^']+)'/", (string) $this->get('/')->headers->get('Content-Security-Policy'), $second);

    expect($first[1] ?? '')->not->toBe('');
    expect($second[1] ?? '')->not->toBe('');
    expect($first[1])->not->toBe($second[1]);
});

it('tags every inline script on an Inertia page with the header nonce', function () {
    $response = $this->actingAs(User::factory()->create())->get('/dashboard');

    $response->assertOk();

    preg_match("/script-src[^;]*'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
    $nonce = $matches[1] ?? '';

    expect($nonce)->not->toBe('');

    $html = (string) $response->getContent();

    // Ziggy's route table is the inline script the app itself needs: without
    // the matching nonce the CSP blocks it and route() breaks in the Vue
    // pages. Ziggy emits `const Ziggy={...}` on the first render in a process
    // and `Object.assign(Ziggy.routes, ...)` on later ones, so match the tag.
    expect($html)->toContain('Ziggy');
    expect($html)->toMatch('/<script type="text\/javascript" nonce="' . preg_quote($nonce, '/') . '"/');

    // @vite adds its own inline asset-prefetch script; it needs the nonce too.
    expect($html)->toContain('<script nonce="' . $nonce . '">');

    // Belt and braces: no inline script at all is left behind without a nonce.
    expect(untrustedInlineScripts($html))->toBe([]);
});
