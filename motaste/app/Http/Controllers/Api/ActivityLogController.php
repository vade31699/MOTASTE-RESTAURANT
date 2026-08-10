<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Replaces public/api/add_activity_log.php.
 */
class ActivityLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $action = trim((string) ($input['action'] ?? ''));
        if ($action === '') {
            return response()->json(['success' => false, 'error' => 'action is required'], 400);
        }

        $actorRole = trim((string) ($input['actorRole'] ?? ''));
        $actorEmail = strtolower(trim((string) ($input['actorEmail'] ?? '')));
        $summary = trim((string) ($input['summary'] ?? ''));
        $details = $input['details'] ?? null;
        $orderId = isset($input['orderId']) ? (int) $input['orderId'] : null;
        $orderNumber = trim((string) ($input['orderNumber'] ?? ''));

        try {
            // Route review-specific events to their dedicated container.
            $isReviewAction = strpos($action, 'review_') === 0;

            if ($isReviewAction) {
                ReviewLogService::write([
                    'review_id' => isset($details['review_id']) && is_array($details) ? (int) $details['review_id'] : null,
                    'action' => $action,
                    'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
                    'actor_email' => $actorEmail !== '' ? $actorEmail : null,
                    'summary' => $summary !== '' ? $summary : null,
                    'details' => is_array($details) ? $details : ['raw' => $details],
                ]);
            } else {
                DB::table('order_activity_logs')->insert([
                    'order_id' => $orderId,
                    'order_number' => $orderNumber !== '' ? $orderNumber : null,
                    'action' => $action,
                    'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
                    'actor_email' => $actorEmail !== '' ? $actorEmail : null,
                    'summary' => $summary !== '' ? $summary : null,
                    'details' => is_array($details)
                        ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : ($details !== null ? (string) $details : null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to save activity log', 'details' => $error->getMessage()], 500);
        }
    }
}
