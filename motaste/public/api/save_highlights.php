<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

// Stateless signed CSRF validation (same as every other gated endpoint).
// NOTE: do NOT compare against getOrCreateCsrfToken() here — tokens are now
// stateless, so that function mints a NEW token every call and the comparison
// would always fail with "Invalid CSRF token".
validateCsrfOrExit();

const HIGHLIGHTS_SNAPSHOT_KEY = 'motaste-highlights';

function failHighlightsRequest(int $status, string $message): never
{
    throw new ApiRequestException($message, $status);
}

function writeHighlightsSnapshot(array $slides): void
{
    $now = now();
    DB::table('highlights_snapshots')->updateOrInsert(
        ['snapshot_key' => HIGHLIGHTS_SNAPSHOT_KEY],
        [
            'snapshot_payload' => json_encode(array_values($slides), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
}

/**
 * Read the stored slides inside the caller's transaction and lock the row, so
 * two admins (or a double-clicked button) cannot clobber each other's change.
 */
function readHighlightSlidesForUpdate(): array
{
    $row = DB::table('highlights_snapshots')
        ->where('snapshot_key', HIGHLIGHTS_SNAPSHOT_KEY)
        ->lockForUpdate()
        ->first();

    return decodeHighlightSlidesPayload($row ? (string) ($row->snapshot_payload ?? '') : null);
}

/**
 * Read a slide position out of the request, rejecting anything that is not a
 * whole number.
 */
function requireHighlightsIndex(array $input, string $failureMessage): int
{
    $index = $input['index'] ?? null;
    if (!is_int($index) && !(is_string($index) && ctype_digit(trim($index)))) {
        failHighlightsRequest(422, $failureMessage);
    }

    return (int) $index;
}

/**
 * Validate incoming slides against the XSS guard and (for new uploads) the
 * per-image size cap. Returns the cleaned, reindexed list.
 */
function requireValidHighlightSlides($value, bool $enforceSizeCap = true): array
{
    $slides = normalizeHighlightSlides($value);

    // Stored-XSS guard: reject anything that is not a plain image URL/data URI
    // (e.g. javascript: URLs or HTML payloads).
    rejectUnsafeInputOrExit($slides);

    if ($enforceSizeCap && findOversizedHighlightSlide($slides) !== null) {
        failHighlightsRequest(422, 'Each highlight image must be smaller than about 1.5 MB. Please upload a smaller image.');
    }

    return $slides;
}

$rawBody = (string) file_get_contents('php://input');

if (trim($rawBody) === '') {
    // PHP discards the request body entirely when it exceeds post_max_size, so
    // an oversized upload arrives here as an empty body. Report that instead of
    // a generic payload error the admin cannot act on.
    $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    http_response_code($declaredLength > 0 ? 413 : 400);
    echo json_encode([
        'success' => false,
        'error' => $declaredLength > 0
            ? 'The upload was larger than the server accepts. Please upload a smaller image.'
            : 'Invalid payload',
    ]);
    exit;
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

// The client uploads ONE image (or one removal) per request: sending the whole
// slideshow back on every change made the request body grow with every stored
// base64 image until it exceeded post_max_size and no upload worked at all.
// `slides` (replace all) is still accepted for previously-cached admin pages.
$action = isset($input['action']) && is_string($input['action']) ? strtolower(trim($input['action'])) : '';
if ($action === '') {
    $action = array_key_exists('slides', $input) ? 'replace' : '';
}

try {
    ensureHighlightsSnapshotTable();

    switch ($action) {
        case 'append':
            $slides = requireValidHighlightSlides([
                isset($input['image']) ? $input['image'] : null,
            ]);
            if ($slides === []) {
                failHighlightsRequest(422, 'No image was received. Please select an image and try again.');
            }

            $stored = DB::transaction(static function () use ($slides): array {
                $updated = appendHighlightSlide(readHighlightSlidesForUpdate(), $slides[0]);
                writeHighlightsSnapshot($updated);

                return $updated;
            });

            echo json_encode(['success' => true, 'count' => count($stored)]);
            break;

        case 'replaceat':
            $index = requireHighlightsIndex($input, 'Unable to update that highlight image. Please refresh and try again.');
            $images = requireValidHighlightSlides([
                isset($input['image']) ? $input['image'] : null,
            ]);
            if ($images === []) {
                failHighlightsRequest(422, 'No image was received. Please select an image and try again.');
            }

            // Used by the dashboard's "optimize stored images" pass: one
            // re-encoded image in, same position out, so the payload stays
            // small even when the slideshow already holds large images.
            $stored = DB::transaction(static function () use ($index, $images): array {
                $updated = replaceHighlightSlideAt(readHighlightSlidesForUpdate(), $index, $images[0]);
                writeHighlightsSnapshot($updated);

                return $updated;
            });

            echo json_encode(['success' => true, 'count' => count($stored)]);
            break;

        case 'remove':
            $index = requireHighlightsIndex($input, 'Unable to remove that highlight image. Please refresh and try again.');

            $stored = DB::transaction(static function () use ($index): array {
                $updated = removeHighlightSlideAt(readHighlightSlidesForUpdate(), $index);
                writeHighlightsSnapshot($updated);

                return $updated;
            });

            echo json_encode(['success' => true, 'count' => count($stored)]);
            break;

        case 'replace':
            if (!isset($input['slides']) || !is_array($input['slides'])) {
                failHighlightsRequest(400, 'Invalid payload');
            }

            // Legacy path (admin pages cached before the per-image upload): the
            // client sends the whole list back, which may still contain images
            // stored before the size cap existed, so only the count is enforced.
            $slides = requireValidHighlightSlides($input['slides'], false);
            if (count($slides) > HIGHLIGHTS_MAX_SLIDES) {
                failHighlightsRequest(422, 'Maximum of ' . HIGHLIGHTS_MAX_SLIDES . ' highlight images is allowed.');
            }

            writeHighlightsSnapshot($slides);
            echo json_encode(['success' => true, 'count' => count($slides)]);
            break;

        default:
            failHighlightsRequest(400, 'Invalid payload');
    }
} catch (ApiRequestException $error) {
    http_response_code($error->status);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('save_highlights failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to save highlights snapshot',
    ]);
}
