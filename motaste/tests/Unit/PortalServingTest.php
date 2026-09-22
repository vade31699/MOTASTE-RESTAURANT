<?php

/**
 * Portal serving guard.
 *
 * The staff/admin portal used to live at public/staff.html. Anything under
 * public/ is handed straight to the browser by the platform's static-file
 * layer, which bypasses the Laravel route — and therefore the SecurityHeaders
 * middleware. So /staff.html was reachable with no CSP, HSTS, nosniff or
 * X-Frame-Options header, and the file's own <meta> CSP cannot substitute for
 * them (frame-ancestors is ignored when delivered via meta).
 *
 * The page now lives outside the web root and is served only by the /staff and
 * /admin routes, so those headers always apply. These guards fail if a copy is
 * ever reintroduced under public/, which would silently reopen the bypass.
 */
function portalPagePath(): string
{
    return __DIR__ . '/../../resources/portal/staff.html';
}

test('the portal page exists outside the web root', function () {
    expect(file_exists(portalPagePath()))->toBeTrue();
});

test('no copy of the portal page exists anywhere under public/', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../public', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getFilename()) === 'staff.html') {
            $offenders[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

test('the portal routes resolve the page from resources, never from public_path', function () {
    $routes = (string) @file_get_contents(__DIR__ . '/../../routes/web.php');

    expect($routes)
        ->toContain("resource_path('portal/staff.html')")
        ->not->toContain("public_path('staff.html')")
        ->not->toContain("public_path('portal/staff.html')");
});

test('the portal page still carries the noindex directive it is served with', function () {
    // The route's X-Robots-Tag header is the server-side half; the page's meta
    // tag is the other. Both must survive a move of the file.
    expect((string) @file_get_contents(portalPagePath()))
        ->toContain('<meta name="robots" content="noindex, nofollow">');
});

test('both legacy .html aliases are permanent redirects to the canonical URL', function () {
    $routes = (string) @file_get_contents(__DIR__ . '/../../routes/web.php');

    // 301, not 302: /staff.html no longer serves the portal itself, so the
    // redirect is the permanent signal that /staff is the canonical URL.
    expect($routes)
        ->toContain("redirect()->route('staff', [], 301)")
        ->toContain("redirect()->route('admin.login', [], 301)");
});
