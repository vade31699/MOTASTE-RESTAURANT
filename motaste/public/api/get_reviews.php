<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

require_once __DIR__ . '/_staff_auth_helpers.php';

try {
    $scope = strtolower(trim((string)($_GET['scope'] ?? 'public')));

    // Staff scope exposes unpublished/pending reviews — staff authentication is
    // required. Public scope is rate-limited per IP.
    if ($scope === 'staff') {
        if (!requireStaffAuth()) {
            abortStaffAuthRequired();
        }
    } else {
        recordOrderApiRequest('get_reviews');
        if (isOrderApiRateLimited('get_reviews', 300, 60)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again shortly.']);
            exit;
        }
    }
    
                
    DB::statement("UPDATE customer_reviews SET publish_status = 'published' WHERE publish_status IS NULL");
    DB::statement("UPDATE customer_reviews SET reviewed_on = COALESCE(reviewed_on, DATE(created_at), CURRENT_DATE) WHERE reviewed_on IS NULL");

    $scope = strtolower(trim((string)($_GET['scope'] ?? 'public')));
    $ratingFilter = (int)($_GET['rating'] ?? 0);

    $query = DB::table('customer_reviews');
    if ($scope !== 'staff') {
        $query->where('publish_status', 'published');
    }
    if ($ratingFilter >= 1 && $ratingFilter <= 5) {
        $query->where('rating', $ratingFilter);
    }

    $reviews = $query
        ->orderByDesc('created_at')
        ->limit(300)
        ->get()
        ->map(function ($row) {
            return [
                'id' => (int)($row->id ?? 0),
                'rating' => (int)($row->rating ?? 0),
                'review_text' => (string)($row->review_text ?? ''),
                'publish_status' => (string)($row->publish_status ?? 'pending'),
                'reviewed_on' => $row->reviewed_on,
                'published_at' => $row->published_at,
                'published_at_iso' => $row->published_at ? Carbon::parse($row->published_at)->toIso8601String() : null,
                'created_at' => $row->created_at,
                'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'reviews' => $reviews]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load reviews']);
}