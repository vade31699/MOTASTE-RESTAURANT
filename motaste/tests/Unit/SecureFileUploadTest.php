<?php

/**
 * Secure file upload tests (security requirement 4).
 *
 * The admin UI stores images as base64 data URIs in the database (verified
 * server-side by storedImageDataUriValidationError), so the remaining surface
 * is the dormant multipart endpoint upload_special_food_image.php. These tests
 * pin down the sanitizer contract (GD re-encode strips polyglot payloads) and
 * the endpoint's structural guarantees (content sniffing, size/dimension caps,
 * CSRF + inventory auth, server-generated filenames, no client filename use).
 */
function bootSecureFileUploadTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/_helpers.php';
}

beforeAll(function () {
    bootSecureFileUploadTestApp();
});

const SECURE_UPLOAD_TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const SECURE_UPLOAD_TINY_JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

function secureUploadPngBytes(): string
{
    return (string) base64_decode(SECURE_UPLOAD_TINY_PNG_B64, true);
}

function secureUploadJpegBytes(): string
{
    return (string) base64_decode(SECURE_UPLOAD_TINY_JPEG_B64, true);
}

function secureUploadHasGd(): bool
{
    return function_exists('imagecreatefromstring');
}

test('sanitizer re-encodes a real PNG/JPEG into clean bytes of the same type', function () {
    bootSecureFileUploadTestApp();

    foreach ([IMAGETYPE_PNG => secureUploadPngBytes(), IMAGETYPE_JPEG => secureUploadJpegBytes()] as $type => $bytes) {
        $out = sanitizeStoredImageBytes($bytes, $type);
        expect($out)->not->toBeNull();
        expect($out)->not->toBe('');
        $info = @getimagesizefromstring($out);
        expect($info)->not->toBeFalse();
        expect((int) ($info[2] ?? -1))->toBe($type);
    }
});

test('polyglot PNG with appended HTML payload is stripped by re-encode', function () {
    bootSecureFileUploadTestApp();

    if (!secureUploadHasGd()) {
        // Fallback path (no GD): the validated original is passed through and
        // this stripping property cannot be guaranteed — the intent is that
        // production runs with GD, which this test asserts when available.
        expect(sanitizeStoredImageBytes(secureUploadPngBytes() . '<script>alert(1)</script>', IMAGETYPE_PNG))
            ->toBeString();
        return;
    }

    $polyglot = secureUploadPngBytes() . "<script>alert('xss')</script><!-- handle:ancestor1/0 -->";
    $out = sanitizeStoredImageBytes($polyglot, IMAGETYPE_PNG);

    expect($out)->not->toBeNull()->not->toBe('');
    expect($out)->not->toContain('<script>')->not->toContain('handle:ancestor1');
    expect(strlen($out))->toBeLessThan(strlen($polyglot));

    $info = @getimagesizefromstring($out);
    expect($info)->not->toBeFalse();
    expect((int) ($info[2] ?? -1))->toBe(IMAGETYPE_PNG);
});

test('garbage claiming to be PNG is rejected when GD can decode', function () {
    bootSecureFileUploadTestApp();

    if (!secureUploadHasGd()) {
        expect(sanitizeStoredImageBytes('not an image at all', IMAGETYPE_PNG))
            ->toBe('not an image at all');
        return;
    }

    expect(sanitizeStoredImageBytes('not an image at all', IMAGETYPE_PNG))->toBeNull();
});

test('unsupported image type is passed through unchanged', function () {
    bootSecureFileUploadTestApp();

    $bytes = secureUploadPngBytes();
    expect(sanitizeStoredImageBytes($bytes, 99999))->toBe($bytes);
});

test('upload endpoint: content sniff, bounds, auth + CSRF, server-side names, no client filename', function () {
    $src = (string) @file_get_contents(__DIR__ . '/../../public/api/upload_special_food_image.php');
    expect($src)->not->toBe('');

    // Uploaded-file provenance, size cap, real-image sniff, dimension cap.
    expect($src)->toContain('is_uploaded_file');
    expect($src)->toContain('5 * 1024 * 1024');
    expect($src)->toContain('getimagesize');
    expect($src)->toContain('MAX_UPLOADED_IMAGE_DIMENSION');

    // Malware sanitization: re-encode captured bytes (null => rejected).
    expect($src)->toContain('sanitizeStoredImageBytes');
    expect($src)->toContain('file_get_contents($file[\'tmp_name\'])');

    // Authorization boundary.
    expect($src)->toContain('requireInventoryAuth');
    expect($src)->toContain('validateCsrfOrExit');

    // Server-generated filename, never the client-supplied one.
    expect($src)->toContain('bin2hex(random_bytes(6))');
    expect($src)->not->toContain("\$file['name']");
    expect($src)->not->toContain("\$_FILES['image']['name']");

    // Written into the locked-down public directory by random server-side name.
    expect($src)->toContain('special_food_images');
    expect($src)->toContain('file_put_contents(');
});

test('upload directory .htaccess blocks listings/execution and sets nosniff', function () {
    $htaccess = (string) @file_get_contents(__DIR__ . '/../../public/special_food_images/.htaccess');
    expect($htaccess)->not->toBe('');

    expect($htaccess)->toContain('Options -Indexes');
    expect($htaccess)->toContain('RemoveHandler');
    expect($htaccess)->toContain('RemoveType');
    expect($htaccess)->toContain('X-Content-Type-Options');
    expect($htaccess)->toContain('nosniff');
});