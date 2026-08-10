<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated menu/highlights endpoints.
 *
 * Replaces: public/api/get_custom_menu.php, save_custom_menu.php,
 * get_highlights.php, save_highlights.php
 */
class MenuController extends Controller
{
    public function menu(Request $request): JsonResponse
    {
        try {
            $snapshot = DB::table('custom_menu_snapshots')->where('snapshot_key', 'motaste-menu')->first();

            return response()->json([
                'success' => true,
                'snapshot' => $snapshot ? json_decode($snapshot->snapshot_payload, true) : null,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load custom menu snapshot', 'details' => $error->getMessage()], 500);
        }
    }

    public function saveMenu(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        try {
            $now = now();
            DB::table('custom_menu_snapshots')->updateOrInsert(
                ['snapshot_key' => 'motaste-menu'],
                [
                    'snapshot_payload' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            return response()->json(['success' => true]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to save custom menu snapshot', 'details' => $error->getMessage()], 500);
        }
    }

    public function highlights(Request $request): JsonResponse
    {
        try {
            $snapshot = DB::table('highlights_snapshots')
                ->where('snapshot_key', 'motaste-highlights')
                ->first();

            $slides = [];
            if ($snapshot && isset($snapshot->snapshot_payload)) {
                $decoded = json_decode((string) $snapshot->snapshot_payload, true);
                if (is_array($decoded)) {
                    $slides = array_values(array_filter($decoded, static function ($item) {
                        return is_string($item) && trim($item) !== '';
                    }));
                }
            }

            return response()->json([
                'success' => true,
                'slides' => array_slice($slides, 0, 15),
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to load highlights snapshot',
                'details' => $error->getMessage(),
            ], 500);
        }
    }

    public function saveHighlights(Request $request): JsonResponse
    {
        $slides = $request->json('slides');
        if (!is_array($slides)) {
            $slides = $request->input('slides', []);
        }

        if (!is_array($slides)) {
            return response()->json(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $slides = array_values(array_filter($slides, static function ($item) {
            return is_string($item) && trim($item) !== '';
        }));

        if (count($slides) > 15) {
            return response()->json([
                'success' => false,
                'error' => 'Maximum of 15 highlight images is allowed.',
            ], 422);
        }

        try {
            $now = now();
            DB::table('highlights_snapshots')->updateOrInsert(
                ['snapshot_key' => 'motaste-highlights'],
                [
                    'snapshot_payload' => json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            return response()->json([
                'success' => true,
                'slides' => $slides,
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to save highlights snapshot',
                'details' => $error->getMessage(),
            ], 500);
        }
    }
}
