<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

try {
    DB::statement("CREATE TABLE IF NOT EXISTS customer_reviews (
        id BIGSERIAL PRIMARY KEY,
        rating INTEGER NOT NULL,
        review_text TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $reviews = DB::table('customer_reviews')
        ->orderByDesc('created_at')
        ->limit(200)
        ->get()
        ->map(function ($row) {
            return [
                'id' => (int)($row->id ?? 0),
                'rating' => (int)($row->rating ?? 0),
                'review_text' => (string)($row->review_text ?? ''),
                'created_at' => $row->created_at,
                'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'reviews' => $reviews]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load reviews', 'details' => $error->getMessage()]);
}