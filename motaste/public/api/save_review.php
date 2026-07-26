<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function ensureReviewTables(): void
{
    DB::statement("CREATE TABLE IF NOT EXISTS customer_reviews (
        id BIGSERIAL PRIMARY KEY,
        rating INTEGER NOT NULL,
        review_text TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    DB::statement("CREATE TABLE IF NOT EXISTS order_activity_logs (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NULL,
        order_number VARCHAR(191) NULL,
        action VARCHAR(100) NOT NULL,
        actor_role VARCHAR(100) NULL,
        actor_email VARCHAR(191) NULL,
        summary TEXT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
}

$input = json_decode(file_get_contents('php://input'), true);
$rating = isset($input['rating']) ? (int)$input['rating'] : 0;
$reviewText = trim((string)($input['reviewText'] ?? ''));

if ($rating < 1 || $rating > 5 || $reviewText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'rating and reviewText are required']);
    exit;
}

try {
    ensureReviewTables();

    $reviewId = DB::table('customer_reviews')->insertGetId([
        'rating' => $rating,
        'review_text' => $reviewText,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => 'review_submitted',
        'actor_role' => 'Customer',
        'actor_email' => null,
        'summary' => 'Customer review submitted',
        'details' => json_encode([
            'review_id' => $reviewId,
            'rating' => $rating,
            'review_text' => $reviewText,
            'submitted_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true, 'reviewId' => $reviewId]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save review', 'details' => $error->getMessage()]);
}