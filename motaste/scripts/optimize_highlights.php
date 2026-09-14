<?php

/**
 * One-off maintenance script for the stored highlight slideshow
 * (highlights_snapshots / 'motaste-highlights').
 *
 * Two jobs, both report-only until --apply:
 *
 *  1. Shrink oversized slides. Images uploaded before the admin UI started
 *     downscaling them are stored as multi-megabyte base64 data URIs, which
 *     makes the homepage fetch a payload of several megabytes. Each one is
 *     re-encoded to slideshow size (same ladder as the browser: max 1600px,
 *     dropping quality until it fits).
 *  2. With --dedupe, drop repeated images. Duplicates are detected by hashing
 *     the decoded image bytes, so the same photo stored twice (or re-encoded
 *     separately) collapses to a single slide; the first occurrence wins and
 *     the slideshow order is preserved. Use this when the homepage rotation
 *     shows the same photo several times in a row.
 *
 * Slides that are not image data URIs, that are GIFs (re-encoding would drop
 * animation), or that are already at slideshow size are left exactly as they
 * are. Nothing is written unless a slide is genuinely smaller or a duplicate.
 *
 * Usage:
 *   php scripts/optimize_highlights.php                     # report only
 *   php scripts/optimize_highlights.php --dedupe            # report, with duplicates marked
 *   php scripts/optimize_highlights.php --apply             # shrink and write
 *   php scripts/optimize_highlights.php --dedupe --apply    # shrink + remove duplicates and write
 *
 * --apply saves a JSON backup of the previous payload under storage/app first.
 * Mirrors HIGHLIGHTS_* in public/api/_helpers.php.
 */

use Illuminate\Support\Facades\DB;

// Keep the same size ladder as shrinkImageDataUrl() in public/script.js.
const OPTIMIZE_TARGET_LENGTH = 700000;
const OPTIMIZE_ATTEMPTS = [
    [1600, 85],
    [1600, 70],
    [1280, 72],
    [1000, 68],
    [800, 62],
];
const OPTIMIZE_SNAPSHOT_KEY = 'motaste-highlights';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/../public/api/_helpers.php';

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$dedupe = in_array('--dedupe', $arguments, true);

$connection = DB::connection();
printf(
    "Database: %s @ %s / %s\n",
    $connection->getDriverName(),
    (string) config('database.connections.' . config('database.default') . '.host'),
    $connection->getDatabaseName()
);
printf("Mode: %s%s\n\n", $apply ? 'APPLY (writes)' : 'DRY RUN (no writes)', $dedupe ? ' + dedupe' : '');

if (!function_exists('imagecreatefromstring')) {
    fwrite(STDERR, "GD is not available, cannot re-encode images.\n");
    exit(1);
}

/**
 * Content hash for a stored slide, so byte-identical images collapse even when
 * their data URI strings differ. Decodes base64 data URIs; hashes the literal
 * value for URLs and anything else, so two references to one file also count as
 * duplicates.
 */
function highlightSlideHash(string $slide): string
{
    if (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $slide) === 1) {
        $binary = base64_decode(substr($slide, strpos($slide, ',') + 1), true);
        if ($binary !== false && $binary !== '') {
            return hash('sha256', $binary);
        }
    }

    return hash('sha256', trim($slide));
}

/**
 * Re-encode one data URI to slideshow size. Returns null when the slide should
 * be left alone (not a usable image, a GIF, or already small enough / not
 * improved by re-encoding).
 */
function optimizeHighlightSlide(string $slide): ?array
{
    if (strlen($slide) <= OPTIMIZE_TARGET_LENGTH) {
        return null;
    }

    if (preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $slide) !== 1) {
        return null;
    }

    $base64 = substr($slide, strpos($slide, ',') + 1);
    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        return null;
    }

    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    if ($width < 1 || $height < 1) {
        imagedestroy($image);
        return null;
    }

    $best = null;
    foreach (OPTIMIZE_ATTEMPTS as [$maxDimension, $quality]) {
        $scale = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // JPEG has no alpha channel; paint white so transparent PNGs do not come
        // out with black boxes behind them (same as the browser path).
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($canvas);

        $candidate = 'data:image/jpeg;base64,' . base64_encode($encoded);
        if ($best === null || strlen($candidate) < strlen($best)) {
            $best = $candidate;
        }
        if (strlen($candidate) <= OPTIMIZE_TARGET_LENGTH) {
            break;
        }
    }

    imagedestroy($image);

    if ($best === null || strlen($best) >= strlen($slide)) {
        return null;
    }

    return [
        'slide' => $best,
        'width' => $width,
        'height' => $height,
        'from' => strlen($slide),
        'to' => strlen($best),
    ];
}

$row = DB::table('highlights_snapshots')->where('snapshot_key', OPTIMIZE_SNAPSHOT_KEY)->first();
if ($row === null) {
    echo "No highlights snapshot row found — nothing to do.\n";
    exit(0);
}

$payload = (string) ($row->snapshot_payload ?? '');
$slides = decodeHighlightSlidesPayload($payload);
printf("Stored slides: %d (payload %s chars)\n\n", count($slides), number_format(strlen($payload)));

// ---------------------------------------------------------------- duplicates
$candidates = [];
$duplicateRows = [];
$seen = [];

foreach ($slides as $index => $slide) {
    $hash = highlightSlideHash($slide);

    if (isset($seen[$hash])) {
        $duplicateRows[] = ['index' => $index, 'first' => $seen[$hash], 'hash' => $hash, 'length' => strlen($slide)];
        continue;
    }

    $seen[$hash] = $index;
    $candidates[] = ['index' => $index, 'slide' => $slide];
}

if ($duplicateRows === []) {
    echo "No duplicate slides found.\n\n";
} else {
    printf(
        "%s: %d slide(s) repeat an earlier image%s\n",
        $dedupe ? 'Removing' : 'Found',
        count($duplicateRows),
        $dedupe ? '' : ' (re-run with --dedupe to remove)'
    );
    foreach ($duplicateRows as $duplicate) {
        printf(
            "  #%d  duplicate of #%d  (%s chars, sha256 %s)\n",
            $duplicate['index'],
            $duplicate['first'],
            number_format($duplicate['length']),
            substr($duplicate['hash'], 0, 12)
        );
    }
    echo "\n";
}

// ------------------------------------------------------------------- shrinking
$updated = [];
$shrunk = 0;
$savedByShrinking = 0;

foreach ($candidates as $candidate) {
    $result = optimizeHighlightSlide($candidate['slide']);

    if ($result === null) {
        $updated[] = $candidate['slide'];
        printf("#%d  keep      %9s chars  %s\n", $candidate['index'], number_format(strlen($candidate['slide'])), substr($candidate['slide'], 0, 24));
        continue;
    }

    $updated[] = $result['slide'];
    $shrunk += 1;
    $savedByShrinking += $result['from'] - $result['to'];
    printf(
        "#%d  shrink    %9s -> %9s chars  (%s KB saved, source %dx%d)\n",
        $candidate['index'],
        number_format($result['from']),
        number_format($result['to']),
        number_format((int) round(($result['from'] - $result['to']) / 1024)),
        $result['width'],
        $result['height']
    );
}

if (!$dedupe) {
    // Leave duplicates in place unless asked: only shrink them.
    foreach ($duplicateRows as $duplicate) {
        $updated[] = $slides[$duplicate['index']];
    }
}

$newPayload = json_encode(array_values($updated), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$changed = $shrunk + ($dedupe ? count($duplicateRows) : 0);

printf("\nSlides: %d -> %d (duplicates dropped: %d)\n", count($slides), count($updated), $dedupe ? count($duplicateRows) : 0);
printf(
    "Shrunk: %d slide(s), %s KB saved\nPayload: %s -> %s chars\n",
    $shrunk,
    number_format((int) round($savedByShrinking / 1024)),
    number_format(strlen($payload)),
    number_format(strlen($newPayload))
);

if ($changed === 0) {
    echo "\nNothing to write.\n";
    exit(0);
}

if (!$apply) {
    echo "\nDry run — re-run with --apply to write these changes.\n";
    exit(0);
}

$backupDirectory = __DIR__ . '/../storage/app';
if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0755, true);
}
$backupPath = $backupDirectory . '/highlights-backup-' . date('Ymd-His') . '.json';
if (file_put_contents($backupPath, $payload) === false) {
    fwrite(STDERR, "Could not write the backup file, refusing to apply.\n");
    exit(1);
}
echo "\nBackup: " . $backupPath . "\n";

DB::transaction(static function () use ($newPayload): void {
    DB::table('highlights_snapshots')
        ->where('snapshot_key', OPTIMIZE_SNAPSHOT_KEY)
        ->lockForUpdate()
        ->first();
    DB::table('highlights_snapshots')
        ->where('snapshot_key', OPTIMIZE_SNAPSHOT_KEY)
        ->update([
            'snapshot_payload' => $newPayload,
            'updated_at' => now(),
        ]);
});

$stored = DB::table('highlights_snapshots')->where('snapshot_key', OPTIMIZE_SNAPSHOT_KEY)->value('snapshot_payload');
printf("Written. Stored payload is now %s chars.\n", number_format(strlen((string) $stored)));
