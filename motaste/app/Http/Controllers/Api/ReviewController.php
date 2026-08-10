<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceAuthService;
use App\Services\ReviewLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated review endpoints.
 *
 * Replaces: public/api/get_reviews.php, save_review.php, publish_review.php,
 * delete_review.php, get_review_logs.php
 *
 * Note: the legacy publish/delete endpoints referenced undefined variables
 * and always returned "Review not found"; this controller fixes that by
 * reading reviewId/actor from the request.
 */
class ReviewController extends Controller
{
    /**
     * Port of get_reviews.php.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Backfill legacy rows that predate status/date columns.
            DB::statement("UPDATE customer_reviews SET publish_status = 'published' WHERE publish_status IS NULL");
            DB::statement('UPDATE customer_reviews SET reviewed_on = COALESCE(reviewed_on, DATE(created_at), CURRENT_DATE) WHERE reviewed_on IS NULL');

            $scope = strtolower(trim((string) $request->query('scope', 'public')));
            $ratingFilter = (int) $request->query('rating', 0);

            // The staff scope exposes unpublished reviews — require a staff session.
            if ($scope === 'staff') {
                $staff = $request->session()->get('staff_session');
                if (!is_array($staff) || ($staff['email'] ?? '') === '') {
                    return response()->json(['success' => false, 'authenticated' => false, 'error' => 'Staff authentication required'], 401);
                }
            }

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
                        'id' => (int) ($row->id ?? 0),
                        'rating' => (int) ($row->rating ?? 0),
                        'review_text' => (string) ($row->review_text ?? ''),
                        'publish_status' => (string) ($row->publish_status ?? 'pending'),
                        'reviewed_on' => $row->reviewed_on,
                        'published_at' => $row->published_at,
                        'published_at_iso' => $row->published_at ? Carbon::parse($row->published_at)->toIso8601String() : null,
                        'created_at' => $row->created_at,
                        'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                    ];
                })
                ->values()
                ->all();

            return response()->json(['success' => true, 'reviews' => $reviews]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load reviews', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of save_review.php (public submission, one per day per reviewer,
     * daily blocks respected).
     */
    public function store(Request $request): JsonResponse
    {
        $rating = (int) $request->input('rating', 0);
        $reviewText = trim((string) $request->input('reviewText', ''));
        $reviewerToken = trim((string) $request->input('reviewerToken', ''));

        $reviewText = strip_tags($reviewText);
        $reviewText = trim((string) preg_replace('/\s+/u', ' ', $reviewText));

        if ($rating < 1 || $rating > 5 || $reviewText === '') {
            return response()->json(['success' => false, 'error' => 'rating and reviewText are required'], 400);
        }

        if (mb_strlen($reviewText) > 500) {
            return response()->json(['success' => false, 'error' => 'Review must be 500 characters or fewer'], 422);
        }

        try {
            // Use the forwarded-header-aware resolver (same as the legacy code)
            // so reviewer-key derivation stays consistent behind a proxy.
            $reviewerKeySource = $reviewerToken !== '' ? 'token:'.$reviewerToken : 'ip:'.DeviceAuthService::resolveClientIpAddress();
            $reviewerKey = hash('sha256', $reviewerKeySource);
            $today = now()->toDateString();

            $isBlockedToday = DB::table('review_daily_blocks')
                ->where('reviewer_key', $reviewerKey)
                ->whereDate('blocked_on', $today)
                ->exists();

            if ($isBlockedToday) {
                return response()->json([
                    'success' => false,
                    'error' => 'Your review was removed by staff today. You can submit a new review tomorrow.',
                ], 409);
            }

            $submittedTodayCount = (int) DB::table('customer_reviews')
                ->where('reviewer_key', $reviewerKey)
                ->whereDate('reviewed_on', $today)
                ->count();

            if ($submittedTodayCount >= 1) {
                return response()->json([
                    'success' => false,
                    'error' => 'You have reached your review limit for today. Submit again tomorrow.',
                ], 409);
            }

            $reviewId = DB::table('customer_reviews')->insertGetId([
                'rating' => $rating,
                'review_text' => $reviewText,
                'reviewer_key' => $reviewerKey,
                'reviewed_on' => $today,
                'publish_status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ReviewLogService::write([
                'review_id' => $reviewId,
                'action' => 'review_submitted',
                'actor_role' => 'Customer',
                'actor_email' => null,
                'summary' => 'Customer review submitted and published',
                'details' => [
                    'review_id' => $reviewId,
                    'rating' => $rating,
                    'review_text' => $reviewText,
                    'publish_status' => 'published',
                    'submitted_at' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'reviewId' => $reviewId,
                'publishStatus' => 'published',
                'message' => 'Review submitted and published. It will appear immediately.',
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to save review', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Fixed port of publish_review.php.
     */
    public function publish(Request $request): JsonResponse
    {
        $reviewId = (int) $request->input('reviewId', 0);
        $actor = $this->actorContext($request);

        if ($reviewId <= 0) {
            return response()->json(['success' => false, 'error' => 'reviewId is required'], 400);
        }

        try {
            $review = DB::table('customer_reviews')->where('id', $reviewId)->first();
            if (!$review) {
                return response()->json(['success' => false, 'error' => 'Review not found'], 404);
            }

            DB::table('customer_reviews')
                ->where('id', $reviewId)
                ->update([
                    'publish_status' => 'published',
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);

            ReviewLogService::write([
                'review_id' => $reviewId,
                'action' => 'review_published',
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => 'Review published',
                'details' => [
                    'review_id' => $reviewId,
                    'rating' => (int) ($review->rating ?? 0),
                    'review_text' => (string) ($review->review_text ?? ''),
                    'published_at' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json(['success' => true, 'reviewId' => $reviewId, 'publish_status' => 'published']);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to publish review', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Fixed port of delete_review.php (blocks the reviewer for the day).
     */
    public function destroy(Request $request): JsonResponse
    {
        $reviewId = (int) $request->input('reviewId', 0);
        $actor = $this->actorContext($request);

        if ($reviewId <= 0) {
            return response()->json(['success' => false, 'error' => 'reviewId is required'], 400);
        }

        try {
            $review = DB::table('customer_reviews')->where('id', $reviewId)->first();
            if (!$review) {
                return response()->json(['success' => false, 'error' => 'Review not found'], 404);
            }

            $reviewerKey = (string) ($review->reviewer_key ?? '');
            if ($reviewerKey !== '') {
                DB::table('review_daily_blocks')->updateOrInsert(
                    [
                        'reviewer_key' => $reviewerKey,
                        'blocked_on' => now()->toDateString(),
                    ],
                    [
                        'reason' => 'deleted_by_admin',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('customer_reviews')->where('id', $reviewId)->delete();

            ReviewLogService::write([
                'review_id' => $reviewId,
                'action' => 'review_deleted',
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => 'Review deleted',
                'details' => [
                    'review_id' => $reviewId,
                    'rating' => (int) ($review->rating ?? 0),
                    'review_text' => (string) ($review->review_text ?? ''),
                    'reviewer_key' => $reviewerKey,
                    'deleted_at' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json(['success' => true]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to delete review', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of get_review_logs.php.
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            ReviewLogService::ensureTable();

            $logs = DB::table('review_activity_logs')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => (int) ($row->id ?? 0),
                        'review_id' => $row->review_id !== null ? (int) $row->review_id : null,
                        'action' => (string) ($row->action ?? ''),
                        'actor_role' => $row->actor_role,
                        'actor_email' => $row->actor_email,
                        'summary' => $row->summary,
                        'details' => $row->details ? json_decode((string) $row->details, true) : null,
                        'created_at' => $row->created_at,
                        'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                    ];
                })
                ->values()
                ->all();

            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load review logs', 'details' => $error->getMessage()], 500);
        }
    }

    private function actorContext(Request $request): array
    {
        $staff = $request->session()->get('staff_session');
        if (is_array($staff) && ($staff['email'] ?? '') !== '') {
            return [
                'role' => (string) ($staff['role'] ?? 'Staff'),
                'email' => strtolower(trim((string) ($staff['email'] ?? ''))),
            ];
        }

        return [
            'role' => trim((string) $request->input('actorRole', 'Staff')) !== '' ? trim((string) $request->input('actorRole', 'Staff')) : 'Staff',
            'email' => strtolower(trim((string) $request->input('actorEmail', ''))) ?: null,
        ];
    }
}
