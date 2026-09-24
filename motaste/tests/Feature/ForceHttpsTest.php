<?php

use Illuminate\Support\Facades\URL;

/**
 * Tests for the ForceHttps middleware (DPA 2.1): plain-HTTP requests are
 * upgraded to HTTPS, and a TLS-terminating proxy is never sent into a redirect
 * loop.
 *
 * The middleware is exempt in the testing environment, so each test that wants
 * it to act overrides config('app.env'). The request scheme is forced to HTTP
 * with URL::forceScheme() and the proxy's headers are supplied as server
 * variables, exactly as a real request presents them.
 */

/**
 * Make the test client issue a plain-HTTP request in a non-exempt environment.
 */
function forceHttpsAsProduction(): void
{
    config(['app.env' => 'production']);
    URL::forceScheme('http');
    URL::forceRootUrl('http://localhost');
}

it('redirects a plain-HTTP request that arrived through a trusted proxy', function () {
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5', // private range: trusted by default
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->get('/')
        ->assertStatus(308)
        ->assertRedirect('https://localhost/');
});

it('preserves the path and query string in the redirect', function () {
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->get('/privacy?lang=en')
        ->assertRedirect('https://localhost/privacy?lang=en');
});

it('uses a method-preserving status so a credential POST is not dropped', function () {
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->post('/login', ['email' => 'a@b.com', 'password' => 'secret'])
        ->assertStatus(308);
});

it('decorates the redirect response with the security headers', function () {
    forceHttpsAsProduction();

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->get('/');

    expect($response->headers->get('Strict-Transport-Security'))->toContain('max-age=');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('leaves an HTTPS request through a trusted proxy alone', function () {
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('/')->assertOk();
});

it('leaves a request alone when a trusted peer forwards no scheme', function () {
    // A proxy that does not forward X-Forwarded-Proto must not be bounced: the
    // scheme is unknowable, and guessing HTTP would cause a redirect loop.
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
    ])->get('/')->assertOk();
});

it('never lets an untrusted peer force a redirect with a forged header', function () {
    // The header is client-supplied. An attacker reaching the app directly must
    // not be able to trigger a redirect (nor can a real HTTPS proxy be bounced
    // when its address is not in the trusted list).
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.9', // public, not a trusted proxy
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('/')->assertOk();
});

it('redirects a direct plain-HTTP client connection', function () {
    // No forwarded header and no TLS signal: nothing relayed the request, so
    // the client provably spoke plain HTTP.
    forceHttpsAsProduction();

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.9',
    ])->get('/')->assertRedirect('https://localhost/');
});

it('does not redirect when the server itself reports TLS', function () {
    // The request is on the https base URL (the server terminated TLS), so the
    // direct TLS signal must win even over a proxy claiming plain HTTP.
    config(['app.env' => 'production']);

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->get('/')->assertOk();
});

it('is exempt in the local and testing environments', function () {
    config(['app.env' => 'local']);
    URL::forceScheme('http');
    URL::forceRootUrl('http://localhost');

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'http',
    ])->get('/')->assertOk();
});
