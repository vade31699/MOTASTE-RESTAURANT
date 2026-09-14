<?php

use Illuminate\Support\Facades\Schema;

/**
 * Tests for the highlights snapshot helpers in public/api/_helpers.php.
 *
 * Background: the admin highlights tab stored every slide as a base64 data URI
 * and re-sent the whole slideshow on every save, so the request body grew past
 * PHP's post_max_size and even a single small image failed with "Invalid
 * payload". Uploads now send one image per request (append) and removals send
 * only a position (remove), which is what these helpers back.
 */
function bootHighlightSlidesTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database (mirrors the other unit
    // tests); never touch the production database.
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
    bootHighlightSlidesTestApp();
});

const TEST_HIGHLIGHT_IMAGE = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';

test('only non-empty strings survive normalization', function () {
    $slides = normalizeHighlightSlides([
        TEST_HIGHLIGHT_IMAGE,
        '',
        '   ',
        null,
        42,
        ['nested'],
        '  data:image/png;base64,iVBORw0KGgo=  ',
    ]);

    expect($slides)->toBe([
        TEST_HIGHLIGHT_IMAGE,
        'data:image/png;base64,iVBORw0KGgo=',
    ]);
});

test('non-array values normalize to an empty slideshow', function () {
    expect(normalizeHighlightSlides(null))->toBe([]);
    expect(normalizeHighlightSlides('data:image/jpeg;base64,abc'))->toBe([]);
});

test('stored snapshot payloads decode into a clean slide list', function () {
    $payload = json_encode([TEST_HIGHLIGHT_IMAGE, '', 7]);

    expect(decodeHighlightSlidesPayload($payload))->toBe([TEST_HIGHLIGHT_IMAGE]);
    expect(decodeHighlightSlidesPayload(null))->toBe([]);
    expect(decodeHighlightSlidesPayload(''))->toBe([]);
    // A corrupt row must not break the slideshow request.
    expect(decodeHighlightSlidesPayload('{not json'))->toBe([]);
});

test('appending a slide keeps the new image last', function () {
    $slides = appendHighlightSlide([TEST_HIGHLIGHT_IMAGE], 'data:image/jpeg;base64,/9j/second');

    expect($slides)->toHaveCount(2);
    expect($slides[1])->toBe('data:image/jpeg;base64,/9j/second');
});

test('appending to a full slideshow is rejected', function () {
    $full = array_fill(0, HIGHLIGHTS_MAX_SLIDES, TEST_HIGHLIGHT_IMAGE);

    expect(fn () => appendHighlightSlide($full, TEST_HIGHLIGHT_IMAGE))
        ->toThrow(ApiRequestException::class);
});

test('removing by position never needs the stored image data', function () {
    $slides = removeHighlightSlideAt([TEST_HIGHLIGHT_IMAGE, 'data:image/jpeg;base64,/9j/second'], 0);

    expect($slides)->toBe(['data:image/jpeg;base64,/9j/second']);
});

test('replacing by position swaps one stored image and keeps the order', function () {
    $slides = replaceHighlightSlideAt(
        [TEST_HIGHLIGHT_IMAGE, 'data:image/jpeg;base64,/9j/second', 'data:image/jpeg;base64,/9j/third'],
        1,
        'data:image/jpeg;base64,/9j/shrunk'
    );

    expect($slides)->toBe([
        TEST_HIGHLIGHT_IMAGE,
        'data:image/jpeg;base64,/9j/shrunk',
        'data:image/jpeg;base64,/9j/third',
    ]);
});

test('replacing an out-of-date position is rejected', function () {
    expect(fn () => replaceHighlightSlideAt([TEST_HIGHLIGHT_IMAGE], 4, TEST_HIGHLIGHT_IMAGE))
        ->toThrow(ApiRequestException::class);
    expect(fn () => replaceHighlightSlideAt([], 0, TEST_HIGHLIGHT_IMAGE))
        ->toThrow(ApiRequestException::class);
});

test('removing an out-of-date position is rejected', function () {
    expect(fn () => removeHighlightSlideAt([TEST_HIGHLIGHT_IMAGE], 3))
        ->toThrow(ApiRequestException::class);
    expect(fn () => removeHighlightSlideAt([TEST_HIGHLIGHT_IMAGE], -1))
        ->toThrow(ApiRequestException::class);
    expect(fn () => removeHighlightSlideAt([], 0))
        ->toThrow(ApiRequestException::class);
});

test('oversized slides are detected before they are stored', function () {
    $ok = str_repeat('a', HIGHLIGHTS_MAX_SLIDE_LENGTH);
    $tooBig = $ok . 'a';

    expect(findOversizedHighlightSlide([TEST_HIGHLIGHT_IMAGE, $ok]))->toBeNull();
    expect(findOversizedHighlightSlide([TEST_HIGHLIGHT_IMAGE, $tooBig]))->toBe($tooBig);
});

test('the highlights snapshot table is created when the migration has not run', function () {
    ensureHighlightsSnapshotTable();

    expect(Schema::hasTable('highlights_snapshots'))->toBeTrue();
    expect(Schema::hasColumn('highlights_snapshots', 'snapshot_payload'))->toBeTrue();
});
