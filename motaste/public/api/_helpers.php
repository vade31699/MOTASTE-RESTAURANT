<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Shared helpers for public API endpoints.
 */

/**
 * Mask an email address for dashboard display (DPA data minimization):
 * "juan.delacruz@gmail.com" -> "j***@gmail.com". Keeps the domain so staff can
 * still distinguish providers, but hides the local part. Non-emails are
 * returned unchanged.
 */
function maskEmailAddressForDisplay(string $email): string
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '') {
        return $email;
    }

    return substr($local, 0, 1) . '***@' . $domain;
}

/**
 * Mask an IPv4/IPv6 address for dashboard display:
 * "112.198.77.9" -> "112.198.*.*", "2001:db8:85a3::8a2e:370:7334" ->
 * "2001:db8:*". Retains enough prefix for staff to correlate repeated
 * abuse from the same network without exposing the full address.
 */
function maskIpAddressForDisplay(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return $ip;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.*.*';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $groups = explode(':', $ip);
        $kept = [];
        foreach ($groups as $group) {
            if (count($kept) >= 2) {
                break;
            }
            $kept[] = $group !== '' ? $group : '0';
        }
        return implode(':', $kept) . ':*';
    }

    return $ip;
}

/**
 * True when a value contains HTML/script injection patterns that must be
 * rejected rather than stored (stored-XSS guard): HTML tags (including
 * malformed ones like "<script>alert<script>"), executable URL schemes, and
 * inline event-handler attributes. Recurses into arrays/objects so whole
 * payloads (e.g. menu snapshots) can be checked in one call. Legitimate
 * image data URIs (data:image/*) are allowed.
 */
function inputContainsUnsafeHtml($value): bool
{
    if (is_array($value) || is_object($value)) {
        foreach ($value as $item) {
            if (inputContainsUnsafeHtml($item)) {
                return true;
            }
        }
        return false;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return false;
    }

    $text = (string)$value;

    // The canonical stored-XSS vector: <script>… (including malformed variants
    // like "<script>alert<script>").
    if (stripos($text, '<script') !== false) {
        return true;
    }

    // Any HTML tag — <img>, <iframe>, <svg>, <a href=…>, closing tags, etc.
    // Requires a tag-like shape so plain text like "a < b" is not rejected.
    if (preg_match('/<\/?[a-z][^>]*>/i', $text) === 1) {
        return true;
    }

    // Executable URL schemes. data: is only dangerous with a non-image payload
    // (data:image/* is how uploaded images are stored); the mime must start
    // with a letter so text like "data: 12/3" is not a false positive.
    if (preg_match('/(?:javascript|vbscript)\s*:/i', $text) === 1) {
        return true;
    }
    if (preg_match('/data\s*:\s*(?!image\/)[a-z][a-z0-9.+-]*\//i', $text) === 1) {
        return true;
    }

    // Inline event handlers, e.g. onerror=, onclick=, onload=.
    if (preg_match('/(?:^|\s)on[a-z]+\s*=/i', $text) === 1) {
        return true;
    }

    return false;
}

/**
 * Reject the request with a 422 when any of the given values (strings or
 * nested arrays/objects) contains HTML/script content, prompting the client
 * to resubmit with plain text. Exits after emitting the JSON error.
 */
function rejectUnsafeInputOrExit(...$values): void
{
    foreach ($values as $value) {
        if (inputContainsUnsafeHtml($value)) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Input contains HTML or script content that is not allowed. Please use plain text only.',
            ]);
            exit;
        }
    }
}

/** Longest accepted staff/admin display name. */
const STAFF_NAME_MAX_LENGTH = 80;

/** Shortest accepted staff/admin display name. */
const STAFF_NAME_MIN_LENGTH = 2;

/**
 * Collapse runs of whitespace and trim a submitted display name so
 * " Juan   Dela  Cruz " and "Juan Dela Cruz" are stored identically.
 */
function normalizeStaffName($name): string
{
    if (!is_string($name) && !is_numeric($name)) {
        return '';
    }

    $name = trim((string)$name);

    return preg_replace('/\s+/u', ' ', $name) ?? $name;
}

/**
 * Validate a staff/admin display name.
 *
 * A name is letters (any script) plus the separators real names use: spaces,
 * hyphens, apostrophes, and periods. Digits, quotes, semicolons, parentheses,
 * backslashes and angle brackets are rejected, so a name can never carry a
 * number, an SQL fragment, markup, or a delimiter that would break out of a
 * query or a log line — even if the request skips the browser form entirely.
 *
 * @param  mixed  $name  The raw submitted value (non-strings are rejected).
 * @return string|null   A human-readable error, or null when the name is valid.
 */
function staffNameValidationError($name): ?string
{
    if (!is_string($name)) {
        return 'Full name is required.';
    }

    $name = normalizeStaffName($name);

    if ($name === '') {
        return 'Full name is required.';
    }

    $length = mb_strlen($name);
    if ($length < STAFF_NAME_MIN_LENGTH) {
        return 'Full name must be at least ' . STAFF_NAME_MIN_LENGTH . ' characters.';
    }

    if ($length > STAFF_NAME_MAX_LENGTH) {
        return 'Full name must be no more than ' . STAFF_NAME_MAX_LENGTH . ' characters.';
    }

    // Every word must start with a letter, and each separator (space, hyphen,
    // apostrophe, or an abbreviation period as in "Ma. Cristina") must be
    // followed by another word — so leading, trailing, or doubled punctuation
    // ("A--B", "Juan.. Cruz", ".Juan") is rejected.
    if (preg_match("/^\\p{L}[\\p{L}\\p{M}]*(?:(?:[- ']|\. ?)[\\p{L}\\p{M}]+)*\.?$/u", $name) !== 1) {
        return 'Full name may only contain letters, spaces, hyphens, apostrophes, and periods.';
    }

    return null;
}

/**
 * A request failure the client should see verbatim, with the HTTP status to
 * answer it with. Thrown by shared helpers so callers can wrap their own
 * database work in a transaction and still roll back cleanly on rejection.
 */
class ApiRequestException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}

/** Maximum number of homepage highlight slides the snapshot may hold. */
const HIGHLIGHTS_MAX_SLIDES = 15;

/**
 * Maximum length of a single stored highlight slide (a base64 data URI, so
 * roughly 1.5 MB of image bytes). The admin UI downscales photos before
 * uploading, so a real slide lands far below this; the cap exists so one
 * oversized image cannot fill the snapshot row or blow past post_max_size.
 */
const HIGHLIGHTS_MAX_SLIDE_LENGTH = 2000000;

/**
 * Keep only usable slide values (non-empty strings) and reindex the list.
 * Used for both incoming requests and stored snapshot payloads so a corrupt or
 * legacy row can never leak non-string entries into the slideshow markup.
 */
function normalizeHighlightSlides($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $slides = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $item = trim($item);
        if ($item === '') {
            continue;
        }

        $slides[] = $item;
    }

    return $slides;
}

/**
 * Return the first slide that exceeds the per-image size cap, or null when all
 * of them fit. Slides are already-normalized strings.
 */
function findOversizedHighlightSlide(array $slides): ?string
{
    foreach ($slides as $slide) {
        if (strlen($slide) > HIGHLIGHTS_MAX_SLIDE_LENGTH) {
            return $slide;
        }
    }

    return null;
}

/**
 * Append one slide to a stored list, enforcing the maximum. Throws an
 * ApiRequestException when the slideshow is already full.
 */
function appendHighlightSlide(array $slides, string $slide): array
{
    $slides = normalizeHighlightSlides($slides);
    if (count($slides) >= HIGHLIGHTS_MAX_SLIDES) {
        throw new ApiRequestException('Maximum of ' . HIGHLIGHTS_MAX_SLIDES . ' highlight images is allowed.');
    }

    $slides[] = trim($slide);

    return $slides;
}

/**
 * Drop the slide at the given position, so a removal never has to send the
 * (potentially huge) stored image data back up. Throws an ApiRequestException
 * when the position no longer exists — the admin page is out of date.
 */
function removeHighlightSlideAt(array $slides, int $index): array
{
    $slides = normalizeHighlightSlides($slides);
    if ($index < 0 || !array_key_exists($index, $slides)) {
        throw new ApiRequestException('That highlight image no longer exists. Please refresh and try again.');
    }

    array_splice($slides, $index, 1);

    return $slides;
}

/**
 * Swap the slide at the given position in place (used to replace an oversized
 * stored image with a smaller re-encoded version without sending the whole
 * slideshow back). Throws an ApiRequestException when the position no longer
 * exists — the admin page is out of date.
 */
function replaceHighlightSlideAt(array $slides, int $index, string $slide): array
{
    $slides = normalizeHighlightSlides($slides);
    if ($index < 0 || !array_key_exists($index, $slides)) {
        throw new ApiRequestException('That highlight image no longer exists. Please refresh and try again.');
    }

    $slides[$index] = trim($slide);

    return $slides;
}

/**
 * Decode a stored highlights snapshot payload into a clean slide list.
 */
function decodeHighlightSlidesPayload(?string $payload): array
{
    if ($payload === null || $payload === '') {
        return [];
    }

    return normalizeHighlightSlides(json_decode($payload, true));
}

/**
 * Ensure the highlights snapshot table exists. Schema is normally managed by
 * Laravel migrations; the inline fallback keeps the admin highlights tab
 * working on a deployment where the migration has not been run yet (otherwise
 * every upload fails with "Unable to save highlights snapshot").
 */
function ensureHighlightsSnapshotTable(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    try {
        if (!Schema::hasTable('highlights_snapshots')) {
            Schema::create('highlights_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('snapshot_key', 191)->unique();
                $table->text('snapshot_payload');
                $table->timestamps();
            });
        }
        $verified = true;
    } catch (Throwable $error) {
        // Surface the failure through the caller's own error handling rather
        // than masking it here.
        error_log('highlights_snapshots table check failed: ' . $error->getMessage());
    }
}

/**
 * Ensure the order preparation timer columns exist. Schema is normally managed
 * by Laravel migrations; the inline fallback keeps order endpoints working even
 * when migrations have not been run on the deployment yet.
 */
function ensureOrderPrepTimerColumns(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    try {
        if (!Schema::hasColumn('orders', 'prep_minutes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('prep_minutes')->nullable();
            });
        }
        if (!Schema::hasColumn('orders', 'prep_started_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('prep_started_at')->nullable();
            });
        }
        $verified = true;
    } catch (Throwable $error) {
        // Schema changes must never block order processing; the migration will
        // apply the columns on deploy.
        error_log('orders prep timer columns check failed: ' . $error->getMessage());
    }
}

/**
 * Ensure the staff login history table exists for the credentials audit trail.
 */
function ensureStaffLoginHistoryTable(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    try {
        if (!Schema::hasTable('staff_login_history')) {
            Schema::create('staff_login_history', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100)->nullable();
                $table->string('full_name', 191)->nullable();
                $table->string('device_label', 191)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('logged_in_at')->nullable();
                $table->timestamps();

                $table->index('email', 'staff_login_history_email_idx');
                $table->index('role', 'staff_login_history_role_idx');
                $table->index('logged_in_at', 'staff_login_history_logged_in_at_idx');
            });
        }
        $verified = true;
    } catch (Throwable $error) {
        // Auditing must never block the login response.
        error_log('staff_login_history table check failed: ' . $error->getMessage());
    }
}

/**
 * Record a successful staff login into the login history audit table.
 */
function recordStaffLoginHistory(string $email, string $role, ?string $fullName = null): void
{
    ensureStaffLoginHistoryTable();

    try {
        DB::table('staff_login_history')->insert([
            'email' => strtolower(trim($email)),
            'role' => trim($role) !== '' ? trim($role) : null,
            'full_name' => trim((string)$fullName) !== '' ? trim((string)$fullName) : null,
            'device_label' => function_exists('resolveDeviceLabel') ? resolveDeviceLabel() : null,
            'user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'ip_address' => function_exists('resolveClientIpAddress') ? resolveClientIpAddress() : null,
            'logged_in_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('staff login history insert failed: ' . $error->getMessage());
    }
}

function normalizeInventoryName(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return mb_strtolower($value);
}

function normalizeItemName(?string $value): string
{
    return normalizeInventoryName($value);
}

/**
 * Build a short human-readable summary from an iterable of order item rows.
 * Accepts arrays or objects with `notes`/`quantity` fields.
 */
function buildOrderSummary($orderItems): string
{
    if (!is_iterable($orderItems)) {
        return '';
    }

    $parts = [];
    foreach ($orderItems as $it) {
        $name = '';
        $qty = 0;
        if (is_object($it)) {
            $name = (string)($it->notes ?? '');
            $qty = (int)($it->quantity ?? 0);
        } elseif (is_array($it)) {
            $name = (string)($it['notes'] ?? '');
            $qty = (int)($it['quantity'] ?? 0);
        }

        $name = trim($name);
        if ($name === '') continue;
        $parts[] = $name . ' x' . $qty;
    }

    return implode(', ', $parts);
}

/** Image MIME types accepted for stored image data URIs (matches the upload endpoint). */
const STORED_IMAGE_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Validate an image stored as a base64 data URI. Only real JPEG/PNG/WebP
 * payloads are accepted: the declared MIME must be an allowlisted format and
 * the decoded bytes must actually be an image (sniffed via getimagesize), so
 * an SVG, a polyglot, or arbitrary "data:image/*" text can never be stored as
 * a highlight/menu image.
 *
 * @return string|null A human-readable error, or null when the data URI is a
 *                     valid image payload.
 */
function storedImageDataUriValidationError(string $dataUri): ?string
{
    if (preg_match('#^data:image/([a-z0-9.+-]+);base64,#i', $dataUri, $m) !== 1) {
        return 'Image data URI is malformed.';
    }

    $type = strtolower($m[1]);
    if (!in_array($type, ['jpeg', 'png', 'webp'], true)) {
        return 'Only JPEG, PNG, or WebP images are supported.';
    }

    $commaPos = strpos($dataUri, ',');
    if ($commaPos === false) {
        return 'Image data URI is malformed.';
    }

    $bytes = base64_decode(substr($dataUri, $commaPos + 1), true);
    if ($bytes === false || $bytes === '') {
        return 'Image data could not be decoded.';
    }

    $info = @getimagesizefromstring($bytes);
    if ($info === false) {
        return 'Uploaded file is not a valid image.';
    }

    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($mime, STORED_IMAGE_ALLOWED_TYPES, true)) {
        return 'Only JPEG, PNG, or WebP images are supported.';
    }

    return null;
}

/**
 * Validate an image http(s) URL: scheme, length cap, and a blocklist of
 * clearly non-image path extensions (.svg, .html, .php, etc.).
 */
function storedImageUrlValidationError(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (mb_strlen($url) > 500) {
        return 'Image URL is too long.';
    }

    if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'Image must be a valid http(s) URL.';
    }

    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('#\.(svg|html?|php\d*|js|css|txt|xml|json)$#i', $path) === 1) {
        return 'Only image URLs are allowed.';
    }

    return null;
}

/**
 * Largest base64 data-URI we will store for an inventory/menu image. The
 * client produces 720x720 JPEG data URIs (a few hundred KB worst case), so
 * this is generous for legitimate uploads while still bounding a single DB
 * write.
 */
const MAX_STORED_IMAGE_DATA_URI_LENGTH = 1500000;

/**
 * Unified image validation for values that may be either a base64 data URI
 * (length-capped and byte-sniffed as real JPEG/PNG/WebP) or an http(s) URL
 * (length-capped, scheme and extension checked). Empty values are allowed.
 * Returns an error message, or null when the value is acceptable.
 */
function storedImageValidationError($image): ?string
{
    if (!is_string($image)) {
        return 'Image must be a string';
    }

    $image = trim($image);
    if ($image === '') {
        return null;
    }

    if (strpos($image, 'data:image/') === 0) {
        if (strlen($image) > MAX_STORED_IMAGE_DATA_URI_LENGTH) {
            return 'Image data URI is too large.';
        }
        return storedImageDataUriValidationError($image);
    }

    return storedImageUrlValidationError($image);
}

/**
 * Largest allowed edge (px) for an uploaded image. Bounds the memory a single
 * decode can allocate, so a gigantic image cannot exhaust the PHP worker even
 * when its file is small.
 */
const MAX_UPLOADED_IMAGE_DIMENSION = 10000;

/**
 * Sanitize already-validated image bytes by decoding and re-encoding them
 * with GD — the "scan for malware where appropriate" step for image uploads.
 * Any polyglot/executable payload trailing a valid JPEG/PNG/WebP/GIF is
 * discarded, and every stored byte is provably a clean image of the declared
 * type. When GD is unavailable for the type the validated original bytes are
 * returned unchanged; when a whitelisted type cannot be decoded (a suspicious
 * payload that nevertheless passed getimagesize) null is returned so the
 * caller rejects the upload.
 *
 * @return string|null Sanitized image bytes, the validated original, or null
 *                     when the payload is not a decodable image.
 */
function sanitizeStoredImageBytes(string $bytes, int $imageType): ?string
{
    $encoders = [
        IMAGETYPE_JPEG => 'imagejpeg',
        IMAGETYPE_PNG => 'imagepng',
        IMAGETYPE_GIF => 'imagegif',
        IMAGETYPE_WEBP => 'imagewebp',
    ];
    $encoder = $encoders[$imageType] ?? null;
    if ($encoder === null || !function_exists('imagecreatefromstring')) {
        return $bytes;
    }

    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        return null;
    }

    $quality = $imageType === IMAGETYPE_JPEG ? 88 : ($imageType === IMAGETYPE_PNG ? 6 : 85);
    ob_start();
    $ok = @$encoder($image, null, $quality);
    $out = ob_get_clean();
    imagedestroy($image);

    if ($ok === false || !is_string($out) || $out === '') {
        return null;
    }

    // The re-encoded output must still be a real image of the SAME type.
    $reInfo = @getimagesizefromstring($out);
    if ($reInfo === false || (int) ($reInfo[2] ?? -1) !== $imageType) {
        return null;
    }

    return $out;
}

/**
 * Strict whole-number ID check. Accepts an int or a digit-only string so a
 * request-supplied id like "1" passes but "1 OR 1=1" or "1abc" is rejected
 * before it ever reaches a WHERE clause.
 */
function isWholeNumberId($value): bool
{
    return is_int($value) || (is_string($value) && ctype_digit($value));
}
